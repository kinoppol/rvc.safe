<?php
/**
 * ไฟล์ตัวอย่างการตั้งค่า - ตัวติดตั้ง (install.php) จะสร้าง config/config.php จากไฟล์นี้
 * อย่าแก้ไขไฟล์ตัวอย่างนี้โดยตรงในการใช้งานจริง
 */
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'rvc_safe',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name'     => 'ระบบเยี่ยมบ้านนักเรียน วอศ.ร้อยเอ็ด',
        'env'      => 'production',   // production | development
        'key'      => '',             // สุ่มอัตโนมัติตอนติดตั้ง
        'installed_at' => null,
    ],
    // การเชื่อมต่อ SSO ผ่านระบบ ONE-RVC — ค่าที่ลงทะเบียนไว้กับ workspace.rvc.ac.th ห้ามแก้
    'sso' => [
        'authorize_endpoint' => 'http://workspace.rvc.ac.th/oa/index.php',
        'verify_endpoint'    => 'http://workspace.rvc.ac.th/oa/api/verify_token.php',
        'client_id'          => 'cl_e433b702f6',
        'redirect_uri'       => 'https://safe.rvc.ac.th/web/api/callback.php',
    ],
];
