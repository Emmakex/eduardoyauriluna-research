<?php
/** Research Manager service bootstrap. */
declare(strict_types=1);

if (! defined('ABSPATH')) { exit; }

final class Eduardo_Research_Manager {
    private static ?Eduardo_Research_Manager_Contract $contract = null;
    private static ?Eduardo_Research_Manager_Diagnostics $diagnostics = null;
    private static ?Eduardo_Research_Manager_Executor $executor = null;
    private static ?Eduardo_Research_Manager_Page_Resource $pages = null;
    private static ?Eduardo_Research_Manager_Insight_Resource $insights = null;
    private static ?Eduardo_Research_Manager_Output_Resource $outputs = null;
    private static ?Eduardo_Research_Manager_Project_Resource $projects = null;
    private static ?Eduardo_Research_Manager_Software_Resource $software = null;
    private static ?Eduardo_Research_Manager_Dataset_Resource $datasets = null;
    private static ?Eduardo_Research_Manager_Rendered_Verifier $rendered = null;

    public static function boot(): void {
        self::$contract = new Eduardo_Research_Manager_Contract();
        self::$diagnostics = new Eduardo_Research_Manager_Diagnostics(self::$contract);
        self::$executor = new Eduardo_Research_Manager_Executor();
        self::$pages = new Eduardo_Research_Manager_Page_Resource(self::$contract);
        self::$insights = new Eduardo_Research_Manager_Insight_Resource(self::$contract);
        self::$outputs = new Eduardo_Research_Manager_Output_Resource(self::$contract);
        self::$projects = new Eduardo_Research_Manager_Project_Resource(self::$contract);
        self::$software = new Eduardo_Research_Manager_Software_Resource(self::$contract);
        self::$datasets = new Eduardo_Research_Manager_Dataset_Resource(self::$contract);
        self::$rendered = new Eduardo_Research_Manager_Rendered_Verifier(self::$contract);
        if (is_admin()) { (new Eduardo_Research_Manager_Admin())->register(); }
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
    public static function insights(): Eduardo_Research_Manager_Insight_Resource {
        if (! self::$insights) { self::$insights = new Eduardo_Research_Manager_Insight_Resource(self::contract()); }
        return self::$insights;
    }
    public static function outputs(): Eduardo_Research_Manager_Output_Resource {
        if (! self::$outputs) { self::$outputs = new Eduardo_Research_Manager_Output_Resource(self::contract()); }
        return self::$outputs;
    }
    public static function projects(): Eduardo_Research_Manager_Project_Resource {
        if (! self::$projects) { self::$projects = new Eduardo_Research_Manager_Project_Resource(self::contract()); }
        return self::$projects;
    }
    public static function software(): Eduardo_Research_Manager_Software_Resource {
        if (! self::$software) { self::$software = new Eduardo_Research_Manager_Software_Resource(self::contract()); }
        return self::$software;
    }
    public static function datasets(): Eduardo_Research_Manager_Dataset_Resource {
        if (! self::$datasets) { self::$datasets = new Eduardo_Research_Manager_Dataset_Resource(self::contract()); }
        return self::$datasets;
    }
    public static function rendered(): Eduardo_Research_Manager_Rendered_Verifier {
        if (! self::$rendered) { self::$rendered = new Eduardo_Research_Manager_Rendered_Verifier(self::contract()); }
        return self::$rendered;
    }
}
