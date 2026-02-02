<?php

// JWT 配置
return [
    // 密钥（生产环境请务必修改）
    'secret' => env('JWT_SECRET', 'your-secret-key-change-in-production'),
    
    // Token 过期时间（秒），默认 2 小时
    'expire' => env('JWT_EXPIRE', 7200),
    
    // 算法
    'algorithm' => env('JWT_ALGORITHM', 'HS256'),
    
    // 颁发者
    'issuer' => env('JWT_ISSUER', 'mall-admin'),
];