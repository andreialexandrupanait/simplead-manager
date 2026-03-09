<?php

namespace App\Enums;

enum SecurityCategory: string
{
    case Hardening = 'hardening';
    case Htaccess = 'htaccess';
    case Login = 'login';
    case Captcha = 'captcha';
    case IpManagement = 'ip_management';
    case ActivityLog = 'activity_log';

    public function label(): string
    {
        return match ($this) {
            self::Hardening => 'WordPress Hardening',
            self::Htaccess => '.htaccess Rules',
            self::Login => 'Login Protection',
            self::Captcha => 'CAPTCHA',
            self::IpManagement => 'IP Management',
            self::ActivityLog => 'Activity Log',
        };
    }
}
