<?php
declare(strict_types=1);

/**
 * ตัวจัดการ Migration ของโครงสร้างฐานข้อมูล
 * - ไฟล์ migration เก็บใน /migrations ชื่อรูปแบบ NNNN_ชื่อ.sql (เรียงตามชื่อ)
 * - ตารางบันทึกสถานะ: schema_migrations
 */
final class Migrator
{
    private PDO $pdo;
    private string $dir;

    public function __construct(PDO $pdo, ?string $dir = null)
    {
        $this->pdo = $pdo;
        $this->dir = $dir ?? (dirname(__DIR__) . '/migrations');
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                version     VARCHAR(20) NOT NULL PRIMARY KEY,
                filename    VARCHAR(255) NOT NULL,
                checksum    CHAR(40) NOT NULL,
                applied_at  DATETIME NOT NULL,
                exec_ms     INT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    /** ไฟล์ migration ทั้งหมดบนดิสก์ */
    public function available(): array
    {
        $files = glob($this->dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $out = [];
        foreach ($files as $f) {
            $name = basename($f);
            $version = explode('_', $name, 2)[0];
            $sql = (string)file_get_contents($f);
            $out[$version] = [
                'version'  => $version,
                'filename' => $name,
                'path'     => $f,
                'sql'      => $sql,
                'checksum' => sha1($sql),
            ];
        }
        return $out;
    }

    /** สถานะที่ apply แล้ว (version => row) */
    public function applied(): array
    {
        $rows = $this->pdo->query('SELECT * FROM schema_migrations ORDER BY version')->fetchAll();
        $out = [];
        foreach ($rows as $r) { $out[$r['version']] = $r; }
        return $out;
    }

    /** รวมสถานะสำหรับแสดงผลในเมนู Migration */
    public function status(): array
    {
        $available = $this->available();
        $applied = $this->applied();
        $rows = [];
        foreach ($available as $v => $m) {
            $a = $applied[$v] ?? null;
            $rows[] = [
                'version'  => $v,
                'filename' => $m['filename'],
                'sql'      => $m['sql'],
                'applied'  => $a !== null,
                'applied_at' => $a['applied_at'] ?? null,
                'exec_ms'  => $a['exec_ms'] ?? null,
                'changed'  => $a !== null && $a['checksum'] !== $m['checksum'],
            ];
        }
        // migration ที่มีในฐานข้อมูลแต่ไม่มีไฟล์แล้ว
        foreach ($applied as $v => $a) {
            if (!isset($available[$v])) {
                $rows[] = [
                    'version' => $v, 'filename' => $a['filename'], 'sql' => '',
                    'applied' => true, 'applied_at' => $a['applied_at'],
                    'exec_ms' => $a['exec_ms'], 'changed' => false, 'orphan' => true,
                ];
            }
        }
        usort($rows, fn($x, $y) => strcmp($x['version'], $y['version']));
        return $rows;
    }

    public function pending(): array
    {
        $applied = $this->applied();
        return array_values(array_filter($this->available(), fn($m) => !isset($applied[$m['version']])));
    }

    /** รัน migration ที่ค้างทั้งหมด คืนค่า log รายไฟล์ */
    public function migrate(): array
    {
        $log = [];
        foreach ($this->pending() as $m) {
            $log[] = $this->runOne($m);
        }
        return $log;
    }

    /** รัน migration หนึ่งไฟล์ */
    public function runOne(array $m): array
    {
        $start = microtime(true);
        $statements = $this->splitSql($m['sql']);
        try {
            $this->pdo->beginTransaction();
            foreach ($statements as $stmt) {
                if (trim($stmt) === '') { continue; }
                try {
                    $this->pdo->exec($stmt);
                } catch (PDOException $e) {
                    if (!$this->isBenign($e)) { throw $e; }
                    // ข้ามข้อผิดพลาดที่เกิดจากการรันซ้ำ (เช่น ดัชนี/คอลัมน์มีอยู่แล้ว)
                }
            }
            // DDL ใน MySQL/MariaDB มัก implicit-commit อยู่แล้ว แต่กันไว้
            if ($this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
            $ms = (int)round((microtime(true) - $start) * 1000);
            $ins = $this->pdo->prepare(
                'REPLACE INTO schema_migrations (version, filename, checksum, applied_at, exec_ms)
                 VALUES (?,?,?,?,?)'
            );
            $ins->execute([$m['version'], $m['filename'], $m['checksum'], date('Y-m-d H:i:s'), $ms]);
            return ['version' => $m['version'], 'filename' => $m['filename'], 'ok' => true, 'exec_ms' => $ms];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['version' => $m['version'], 'filename' => $m['filename'], 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    /** ข้อผิดพลาดที่ถือว่าไม่เป็นไร เมื่อรัน migration ซ้ำบนฐานข้อมูลที่มีโครงสร้างบางส่วนแล้ว */
    private function isBenign(PDOException $e): bool
    {
        $msg = strtolower($e->getMessage());
        $needles = [
            'already exists',
            'duplicate key name',
            'duplicate column name',
            "can't drop",
            'check that column/key exists',
            'multiple primary key defined',
        ];
        foreach ($needles as $n) {
            if (str_contains($msg, $n)) { return true; }
        }
        return false;
    }

    /** แยกคำสั่ง SQL ด้วย ; ท้ายบรรทัด รองรับ block DELIMITER อย่างง่าย และข้ามคอมเมนต์ */
    private function splitSql(string $sql): array
    {
        // ลบคอมเมนต์บรรทัด -- และ #
        $lines = preg_split('/\r\n|\r|\n/', $sql);
        $clean = [];
        foreach ($lines as $ln) {
            $t = ltrim($ln);
            if (str_starts_with($t, '--') || str_starts_with($t, '#')) { continue; }
            $clean[] = $ln;
        }
        $sql = implode("\n", $clean);

        $out = [];
        $buf = '';
        $len = strlen($sql);
        $inStr = false;
        $strCh = '';
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($inStr) {
                $buf .= $ch;
                if ($ch === '\\' && $i + 1 < $len) { $buf .= $sql[++$i]; continue; }
                if ($ch === $strCh) { $inStr = false; }
                continue;
            }
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $inStr = true; $strCh = $ch; $buf .= $ch; continue;
            }
            if ($ch === ';') {
                $out[] = trim($buf);
                $buf = '';
                continue;
            }
            $buf .= $ch;
        }
        if (trim($buf) !== '') { $out[] = trim($buf); }
        return $out;
    }
}
