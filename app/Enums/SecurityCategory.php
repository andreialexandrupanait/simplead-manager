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
    case Performance = 'performance';
    case SiteControl = 'site_control';
    case AdminUx = 'admin_ux';
    case ContentMedia = 'content_media';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Hardening => 'WordPress Hardening',
            self::Htaccess => '.htaccess Rules',
            self::Login => 'Login Protection',
            self::Captcha => 'CAPTCHA',
            self::IpManagement => 'IP Management',
            self::ActivityLog => 'Activity Log',
            self::Performance => 'Performance',
            self::SiteControl => 'Site Control',
            self::AdminUx => 'Admin UX',
            self::ContentMedia => 'Content & Media',
            self::Email => 'Email / SMTP',
        };
    }

    public function isTweakCategory(): bool
    {
        return in_array($this, [
            self::Performance,
            self::SiteControl,
            self::AdminUx,
            self::ContentMedia,
            self::Email,
        ]);
    }
}
