<?php
/** Research Manager service bootstrap. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager {
    private static ?Eduardo_Research_Manager_Contract $contract = null;
    private static ?Eduardo_Research_Manager_Diagnostics $diagnostics = null;
    private static ?Eduardo_Research_Manager_Executor $executor = null;
    private static ?Eduardo_Research_Manager_Page_Resource $pages = null;

    public static function boot(): void {
        self::$contract = new Eduardo_Research_Manager_Contract();
        self::$diagnostics = new Eduardo_Research_Manager_Diagnostics(self::$contract);
        self::$executor = new Eduardo_Research_Manager_Executor();
        self::$pages = new Eduardo_Research_Manager_Page_Resource(self::$contract);
        if (is_admin()) {
            (new Eduardo_Research_Manager_Admin())->register();
        }
    }

    public static function contract(): Eduardo_Research_Manager_Contract {
        if (! self::$contract) { self::$contract = new Eduardo_Research_Manager_Contract(); }
        return self::$contract;
    }

    public static function diagnostics(): Eduardo_Research_Manager_Diagnostics {
        if (! self::$diagnostics) { self::$diagnostics = new Eduardo_Research_Manager_Diagnostics(self::contract()); }
        return self::$diagnostics;
    }

    public static function executor(): Eduardo_Research_Manager_Executor {
        if (! self::$executor) { self::$executor = new Eduardo_Research_Manager_Executor(); }
        return self::$executor;
    }

    public static function pages(): Eduardo_Research_Manager_Page_Resource {
        if (! self::$pages) { self::$pages = new Eduardo_Research_Manager_Page_Resource(self::contract()); }
        return self::$pages;
    }
}
