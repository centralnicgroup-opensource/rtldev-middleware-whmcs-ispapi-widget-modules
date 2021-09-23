<?php

/**
 * WHMCS ISPAPI Modules Dashboard Widget
 *
 * This Widget allows to display your installed ISPAPI modules and check for new versions.
 *
 * @see https://github.com/hexonet/whmcs-ispapi-widget-modules/wiki/
 *
 * @copyright Copyright (c) Kai Schwarz, HEXONET GmbH, 2019
 * @license https://github.com/hexonet/whmcs-ispapi-widget-modules/blob/master/LICENSE/ MIT License
 */

namespace WHMCS\Module\Widget;

use App;
use ZipArchive;

const ISPAPI_LOGO_URL = "https://raw.githubusercontent.com/hexonet/whmcs-ispapi-registrar/master/modules/registrars/ispapi/logo.png";
const ISPAPI_REGISTRAR_GIT_URL = "https://github.com/hexonet/whmcs-ispapi-registrar";

if (!class_exists('WHMCS\Module\Widget\IspapiBaseWidget')) {
    class IspapiBaseWidget extends \WHMCS\Module\AbstractWidget
    {

        protected string $widgetid;

        public function __construct(string $id)
        {
            $this->widgetid = $id;
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }
        }

        /**
         * Fetch data that will be provided to generateOutput method
         * @return mixed data array or null in case of an error
         */
        public function getData()
        {
            $status = \App::getFromRequest("status");
            if ($status !== "") {
                $status = (int)$status;
                if (in_array($status, [0,1])) {
                    Setting::setValue($this->widgetid, $status);
                }
                return [
                    "success" => (int)Setting::getValue($this->widgetid) === $status
                ];
            }

            $status = Setting::getValue($this->widgetid);
            if (is_null($status)) {
                $status = 1;
            }
            return [
                "status" => (int)$status
            ];
        }

        /**
         * generate widget"s html output
         * @param mixed $data input data (from getData method)
         * @return string html code
         */
        public function generateOutput($data)
        {
            // missing or inactive registrar Module
            if ($data["status"] === -1) {
                $gitURL = ISPAPI_REGISTRAR_GIT_URL;
                $logoURL = ISPAPI_LOGO_URL;
                return <<<HTML
                    <div class="widget-content-padded widget-billing">
                        <div class="color-pink">
                            Please install or upgrade to the latest HEXONET ISPAPI Registrar Module.
                            <span data-toggle="tooltip" title="The HEXONET ISPAPI Registrar Module is regularly maintained, download and documentation available at github." class="glyphicon glyphicon-question-sign"></span><br/>
                            <a href="{$gitURL}">
                                <img src="{$logoURL}" width="125" height="40"/>
                            </a>
                        </div>
                    </div>
                HTML;
            }

            // show our widget
            $html = "";
            if ($data["status"] === 0) {
                $html = <<<HTML
                <div class="widget-billing">
                    <div class="row account-overview-widget">
                        <div class="col-sm-12">
                            <div class="item">
                                <div class="note">
                                    Widget is currently disabled. Use the first icon for enabling.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                HTML;
            }
            // Data Refresh Request -> avoid including JavaScript
            if (empty($_REQUEST["refresh"])) {
                $ico = ($data["status"] === 1) ? "on" : "off";
                $wid = ucfirst($this->widgetid);
                $html = <<<HTML
                {$html}
                <script type="text/javascript">
                if (!$("#panel${wid} .widget-tools .hx-widget-toggle").length) {
                    $("#panel${wid} .widget-tools").prepend(
                        `<a href="#" class="hx-widget-toggle" data-status="${data["status"]}">
                            <i class=\"fas fa-toggle-${ico}\"></i>
                        </a>`
                    );
                }
                $("#panel${wid} .hx-widget-toggle").off().on("click", function (event) {
                    $(this).find("i[class^=\"fas fa-toggle-\"]").attr("class", "fas fa-spinner fa-spin");
                    event.preventDefault();
                    const newstatus = (1 - $(this).data("status"));
                    const url = WHMCS.adminUtils.getAdminRouteUrl("/widget/refresh&widget=${wid}&status=" + newstatus)
                    WHMCS.http.jqClient.post(url, function (json) {
                        if (json.success && (JSON.parse(json.widgetOutput)).success) {
                            window.location.reload(); // widget refresh doesn't update the height
                        }
                    }, 'json');
                });
                </script>
                HTML;
            }

            return $html;
        }
    }
}

/**
 * ISPAPI Modules Widget.
 */
class IspapiModulesWidget extends IspapiBaseWidget
{
    const VERSION = "2.1.1";

    protected $title = 'HEXONET ISPAPI Modules Overview';
    protected $description = '';
    protected $weight = 150;
    protected $columns = 1;
    protected $cache = false;
    protected $cacheExpiry = 120;
    protected $requiredPermission = '';

    public function __construct() {
        parent::__construct("ispapiModulesWidget");
    }

    /**
     * return html code for error case specified by given error message
     * @param string $errMsg error message to show
     * @return string html code
     */
    private function returnError($errMsg)
    {
        return <<<EOF
                <div class="widget-content-padded widget-billing">
                    <div class="color-pink">$errMsg</div>
                </div>
                EOF;
    }

    /**
     * Fetch data that will be provided to generateOutput method
     * @return array|null data array or null in case of an error
     */
    public function getData()
    {
        // action handler
        $fn = App::getFromRequest('action');
        if (!empty($fn)) {
            $module = App::getFromRequest('module');
            $type = App::getFromRequest('type');
            
            $mod = ModuleFactory::getModule($module, $type);
            if ($mod && is_callable([$mod, $fn])) {
                return $mod->$fn();
            }
            return [
                "success" => false,
                "module" => $module,
                "type" => $type,
                "result" => 'Unknown Action requested.'
            ];
        }

        // now load fresh data
        return array_merge(parent::getData(), [
            "groups" => ModuleFactory::getModuleGroups()
        ]);
    }

    /**
     * generate widget's html output
     * @param array $data input data (from getData method)
     * @return string html code
     */
    public function generateOutput($data)
    {
        if (isset($data["success"])) {
            return json_encode($data) ?: "[\"success\":false]";
        }

        // widget controls / status switch
        $html = parent::generateOutput($data);

        // Inactive Widget (0)
        if ($data["status"] !== 1) {
            return $html;
        }

        // generate HTML
        $html .= ModuleFactory::getHTML($data["groups"]);

        // Data Refresh Request -> avoid including JavaScript
        if (!empty($_REQUEST["refresh"])) {
            return $html;
        }

        return <<<HTML
            {$html}
            <script type="text/javascript">
            //hxStartCounter("#balexpires");
            </script>
        HTML;
    }
}

class ModuleFactory {

    private static $map = [
        "ispapibackorder" => [
            "repoid" => "whmcs-ispapi-backorder",
            "name" => "Backorder Add-on",
            "type" => "addon", // type (registrar, addon)
            "cleanup_files" => ['/modules/addons/ispapibackorder'],
            "install_files" => ['/modules/addons/ispapibackorder'],
            "dependencies" => [
                "required" => [
                    "ispapi"
                ]
            ],
            "prio" => 8
        ],
        "ispapipremiumdns_addon" => [
            "repoid" => "whmcs-ispapi-premiumdns",
            "whmcsserverid" => "ispapipremiumdns",
            "name" => "Premium DNS Server",
            "type" => "addon",
            "deprecated" => [
                "case" => "product", # case of product deprecation
                "notice" => "Product stopped on 1st of April 2021. You can still manage your existing Premium DNS Zones and their Resource Records. Ordering new ones will fail.",
                "url" => "https://www.hexonet.net/blog/dns-to-serve-you-better",
                "replacement" => "whmcs-dns"
            ],
            "dependencies" => [
                "required" => [
                    "ispapi"
                ]
            ],
            "cleanup_files" => ['/modules/addons/ispapipremiumdns'],
            "install_files" => ['/modules/addons/ispapipremiumdns'],
            "prio" => 6
        ],
        "ispapissl_addon" => [
            "repoid" => "whmcs-ispapi-ssl",
            "whmcsserverid" => "ispapissl",
            "name" => "SSL Add-on",
            "type" => "addon",
            "cleanup_files" => ['/modules/addons/ispapissl_addon', '/modules/servers/ispapissl'],
            "install_files" => ['/modules/addons/ispapissl_addon', '/modules/servers/ispapissl'],
            "dependencies" => [
                "required" => [
                    "ispapi"
                ]
            ],
            "prio" => 7
        ],
        "ispapidomaincheck" => [
            "repoid" => "whmcs-ispapi-domainchecker",
            "name" => "Domain Checker Add-on",
            "type" => "addon",
            "cleanup_files" => ['/modules/addons/ispapidomaincheck'],
            "install_files" => ['/modules/addons/ispapidomaincheck'],
            "dependencies" => [
                "required" => [
                    "ispapi",
                    "ispapibackorder" // for testing only. TODO: remove this line
                ]
            ],
            "prio" => 9
        ],
        "ispapidpi" => [
            "repoid" => "whmcs-ispapi-pricingimporter",
            "name" => "Price Importer Add-on",
            "type" => "addon",
            "deprecated" => [
                "notice" => "Module is no longer maintained as of the new \"Registrar TLD Sync Feature\" Feature of WHMCS. ",
                "url" => "https://docs.whmcs.com/Registrar_TLD_Sync",
                "case" => "whmcs",
                "whmcs_version" => "7.10.0",
                "replacement" => "ispapi"
            ],
            "cleanup_files" => ["/modules/addons/ispapidpi"],
            "install_files" => ["/modules/addons/ispapidpi"],
            "dependencies" => [
                "required" => [
                    "ispapi"
                ]
            ],
            "prio" => 5
        ],
        "ispapi" => [
            "repoid" => "whmcs-ispapi-registrar",
            "name" => "Registrar Module",
            "type" => "registrar",
            "cleanup_files" => ['/modules/registrars/ispapi'],
            "install_files" => ['/modules/registrars/ispapi'],
            "dependencies" => [
                "required" => []
            ],
            "prio" => 10
        ],
        "ispapidomainimport" => [
            "repoid" => "whmcs-ispapi-domainimport",
            "name" => "Domain Importer Add-on",
            "type" => "addon",
            "cleanup_files" => ['/modules/addons/ispapidomainimport'],
            "install_files" => ['/modules/addons/ispapidomainimport'],
            "dependencies" => [
                "required" => [
                    "ispapi"
                ]
            ],
            "prio" => 4
        ],
        "ispapiwidgetaccount" => [
            "repoid" => "whmcs-ispapi-widget-account",
            "name" => "Account Widget",
            "type" => "widget",
            "cleanup_files" => ['/modules/widgets/ispapi_account.php'],
            "install_files" => ['/modules/widgets/ispapi_account.php'],
            "dependencies" => [
                "required" => [
                    "ispapi"
                ]
            ],
            "prio" => 2
        ],
        "ispapiwidgetmodules" => [
            "repoid" => "whmcs-ispapi-widget-modules",
            "name" => "Modules Widget",
            "type" => "widget",
            "cleanup_files" => ['/modules/widgets/ispapi_modules.php'],
            "install_files" => ['/modules/widgets/ispapi_modules.php'],
            "dependencies" => [
                "required" => ["ispapi"]
            ],
            "prio" => 0
        ],
        "ispapiwidgetmonitoring" => [
            "repoid" => "whmcs-ispapi-widget-monitoring",
            "name" => "Monitoring Widget",
            "type" => "widget",
            "cleanup_files" => ['/modules/widgets/ispapi_monitoring.php'],
            "install_files" => ['/modules/widgets/ispapi_monitoring.php'],
            "dependencies" => [
                "required" => [
                    "ispapi"
                ]
            ],
            "prio" => 1
        ]
    ];

    public static function getDependenciesMap($not_installed_modules, $installed_modules)
    {
        // get the module dependencies, and check if they are installed
        $dependencies_arr = [];
        foreach ($not_installed_modules as $module) {
            // get its dependencies
            $id = $module->getWHMCSModuleId();
            $dependencies = self::$map[$id]['dependencies']['required'];
            if (count($dependencies) > 0) {
                foreach ($dependencies as $dependency) {
                    $dependencies_arr[$id][$dependency] = false;
                    foreach ($installed_modules as $installed) {
                        if ($installed->getWHMCSModuleId() === $dependency) {
                            $dependencies_arr[$id][$dependency] = true;
                            continue; // continue to next dependency
                        }
                    }
                }
            }
        }
        return $dependencies_arr;
    }

    //TODO: doesn't it make sense to deprecate the "type" parameter
    //for xhr requests as it is accessible by class?
    public static function getModule($moduleid, $data) {
        if (!isset(self::$map[$moduleid])) {
            return null;
        }
        $type = self::$map[$moduleid]["type"];
        $cl = "\\WHMCS\\Module\\Widget\\" . ucfirst($data["type"]);
        return new $cl($moduleid, $data);
    }

    public static function getModuleGroups() {
        // TODO: why not directly using STATUS_INSTALLED as Group?
        // Status is still accessible by Module Instance
        foreach(self::$map as $module => $rawData){
            $mod = self::getModule($module, $rawData);
            if (is_null($mod)) {
                continue;
            }
            $status = $mod->getStatus();
            self::addToModuleGroup($status, $mod);
            switch ($status) {
                case Module::STATUS_ACTIVE:
                    self::addToModuleGroup(Module::STATUS_INSTALLED, $mod);
                    break;
                case Module::STATUS_INACTIVE:
                case Module::STATUS_NOTINSTALLED:
                    self::addToModuleGroup(Module::STATUS_NOTINSTALLED, $mod);
                    break;
                default:
                    break;
            }
        }        
        return self::$moduleGroups;
    }

    public static function addToModuleGroup($type, $module) {
        $grp = self::getModuleGroup($type);
        if (is_null($grp)) {
            $grp = new ModuleGroup($type);
            self::$moduleGroups[$type] = $grp;
        }
        $grp->add($module);
    }

    public static function getModuleGroup($type) {
        if (isset(self::$moduleGroups[$type])) {
            return self::$moduleGroups[$type];
        }
        return null;
    }

    public static function getHTML($moduleGroups) {
        $smarty = new \WHMCS\Smarty(true);
        // assign input values
        foreach($moduleGroups as $type => $grp) {
            $smarty->assign($type, $grp);
        }

        $grpInstalled = self::getModuleGroup(Module::STATUS_INSTALLED);
        $grpNotInstalled = self::getModuleGroup(Module::STATUS_NOTINSTALLED);
        $grpDeprecated = self::getModuleGroup(Module::STATUS_DEPRECATED);

        // get required js code
        $jscript = self::generateOutputJS(
            $grpNotInstalled->getModules(),
            $grpInstalled->getModules()
        );

        return <<<HTML
            <div class="widget-content-padded">
                <div class="row small">
                    <ul class="nav nav-tabs">
                        <li class="active">
                            <a data-toggle="tab" href="#tab1">{$grpInstalled->getTabLabel()}</a>
                        </li>
                        <li>
                            <a data-toggle="tab" href="#tab2">{$grpNotInstalled->getTabLabel()}</a>
                        </li>
                        <li>
                            <a data-toggle="tab" href="#tab3">{$grpDeprecated->getTabLabel()}</a>
                        </li>
                    </ul>
                    <div class="tab-content small">
                        <div id="tab1" class="tab-pane fade in active">
                            {$grpInstalled->getTabBody()}
                        </div>
                        <div id="tab2" class="tab-pane fade">
                            {$grpNotInstalled->getTabBody()}
                        </div>
                        <div id="tab3" class="tab-pane fade">
                            {$grpDeprecated->getTabBody()}
                        </div>
                    </div>
                </div>
            </div>
            {$jscript}
        HTML;

        // parse content
        $content = '<div class="widget-content-padded">
            <div class="row small">
                <div class="tab-content small">
                    <div id="tab3" class="tab-pane fade">
                        {if empty($deprecated)}
                            <div class="widget-content-padded">
                                <div class="text-center">No modules found.</div>
                            </div>
                        {else}
                            <table class="table table-bordered table-condensed hxmwTable">
                                <thead>
                                    <tr>
                                        <th scope="col"><input onChange="selectUnselectCheckboxs(this, \'deinstall\');" type="checkbox" class="form-check-input" id="checkallRemove"></th>
                                        <th scope="col">Name</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {foreach $deprecated as $module}
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="deinstall-checkbox" onChange="checkboxChange(this, \'deinstall\');" id="{$module->getWHMCSModuleId()}">
                                            </td>
                                            <td>{$module->getName()}</td>
                                            <td>
                                                {if $module->isDeprecated()}
                                                    <span class="textred small">Deprecated</span>
                                                {elseif $module->isInactive()}
                                                    <span class="textorange small">Not Activated</span>
                                                {elseif $module->isActive()}
                                                    <span class="textorange small">Activated/Installed</span>
                                                {else}
                                                    <span class="textorange small">Not Installed</span>
                                                {/if}
                                            </td>
                                            <td>
                                                {if $module->isActive() || $module->isInactive()}//not installed
                                                    <button class="btn btn-danger btn-xs removebtn" m-status="module.status wtf?" m-action="removeModule" m-type="{$module->getType()}" module="{$module->getWHMCSModuleId()}" data-toggle="tooltip" data-placement="top" title="Remove">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                {/if}
                                                {if $module->isStandardDeprecation()}
                                                    <button class="btn btn-warning btn-xs toggleDetailsView" m-type="{$module->getWHMCSModuleId()}-details" data-toggle="tooltip" data-placement="top" title="Show Details">
                                                        <i class="fas fa-caret-down"></i>
                                                    </button>
                                                {/if}
                                            </td>
                                        </tr>
                                        {if !$module->isStandardDeprecation()}
                                            <tr>
                                                <td id="{$module->getWHMCSModuleId()}-details" class="bg-warning" colspan="4" style="display: none;">
                                                    {$module->getDeprecationgNotice()}
                                                    <!-- TODO
                                                    if $module->isProductDeprecation()
                                                        $module->getDeprecationgNotice().
                                                        Read more: <a href="$module.url" target=_blank>here.</a>
                                                        if $module.replacement
                                                        Replacement available: $module.replacement.
                                                    else
                                                        Deprecated since WHMCS $module.whmcs_version. 
                                                        $module.notice
                                                        Read more: <a href="$module.url" target=_blank>here.</a>
                                                        if $module.replacement
                                                            Replacement available: $module.replacement.
                                                        /if
                                                    /if
                                                    -->
                                                </td>
                                            </tr>
                                        {/if}
                                    {foreachelse}
                                        <span class="text-center">No modules found.</span>
                                    {/foreach}
                                </tbody>
                            </table>
                            <div>
                                <div class="col-sm-12 hxmwBttnGrp">
                                    <button disabled class="btn btn-success btn-sm" onclick="deinstallModules();" id="btn-deinstall">Remove Selected <i class="fas fa-arrow-right"></i></button>
                                    <div class="text-warning hxmwSpin" id="deinstallation-div">
                                        <i class="fas fa-spinner fa-spin"></i>
                                        <span id="removal-notice" >Please wait, Removing x </span>
                                    </div>
                                </div>
                            </div>
                        {/if}
                    </div><!-- // tab3 -->
                </div><!-- tab container -->
            </div><!-- div small row -->
        </div><!-- widget container -->
        
        <!-- JavaScript Entry Point -->
        {$jscript}

        <!-- Modal for Deprecation alerts-->
        <div class="modal fade" id="alertModal" tabindex="-1" role="dialog" aria-labelledby="alertModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    ...
                </div>
                <div class="modal-footer">
                    <button type="button" id="alertModalDismiss" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
                </div>
            </div>
        </div>
        
        <!-- Modal for other alerts -->
        <div class="modal fade" id="alertModalOther" tabindex="-1" role="dialog" aria-labelledby="alertModalTitle" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body-alert">
                    ...
                </div>
                <div class="modal-footer">
                    <button type="button" id="alertModalDismiss" class="btn btn-secondary" data-dismiss="modal">Close</button>
                </div>
                </div>
            </div>
        </div>';
        return $smarty->fetch('eval:' . $content);
    }

    private static function generateOutputJS($not_installed_modules, $installed_modules_ids)
    {
        // TODO: why not building the dependency map for just all modules?
        $dependencies_arr_not_installed = ModuleFactory::getDependenciesMap($not_installed_modules, $installed_modules_ids); // get dependencies for not installed modules
        $dependencies_arr_installed = ModuleFactory::getDependenciesMap($installed_modules_ids, []); // get dependencies for installed modules
        $dependencies_arr = json_encode(array_merge($dependencies_arr_not_installed, $dependencies_arr_installed));
        return <<<HTML
            <script type="text/javascript">
                const dependency_map = $dependencies_arr;
                $('.activatebtn, .deactivatebtn').on('click', hxmwActivateDeactivateHandler)
                $('.toggleDetailsView').on('click', hxmwToggleDetailsHandler);
                $('.removebtn').on('click', hxmwRemovalHandler);
                $(document).on('hidden.bs.modal','#alertModal', hxmwRefreshWidgetHandler);
            </script>
        HTML;
    }

    private static $moduleGroups = [];
}

class ModuleGroup {
    private $size = 0;
    private $modules;
    private $name;
    private $type;

    public function __construct($type) {
        $this->modules = [];
        $this->size = 0;
        $this->type = $type;
        switch($type) {
            case Module::STATUS_NOTINSTALLED:
                $this->name = "Not Installed/Inactive";
                break;
            case Module::STATUS_INSTALLED:
                $this->name = "Installed/Active";
                break;
            default:
                $this->name = ucfirst($type);
                break;
        }
    }

    public function getTabLabel() {
        switch($this->type) {
            case Module::STATUS_DEPRECATED:
                $color = "danger";
                break;
            case Module::STATUS_INACTIVE:
            case Module::STATUS_NOTINSTALLED:
                $color = "warning";
                break;
            default:
                $color = "success";
                break;
        }
        return <<<HTML
            {$this->getName()} <span class="small bg-{$color} hxwmTabs">{$this->getSize()}</span>
        HTML;
    }

    public function getTabBody() {
        if ($this->isEmpty()) {
            return <<<HTML
                <div class="widget-content-padded">
                    <div class="text-center">No modules found.</div>
                </div>
            HTML;
        }

        $modhtml = '';
        foreach ($this->modules as $m) {
            $modhtml .= $m->getHTML();
        }

        //TODO move event handler to jscript       
        
        if ($this->type === Module::STATUS_INSTALLED) {
            return <<<HTML
                <table class="table table-bordered table-condensed hxmwTable">
                    <thead>
                        <tr>
                            <th scope="col"><input onChange="selectUnselectCheckboxs(this, \'upgrade\');" type="checkbox" class="form-check-input" id="checkallUpgrade"></th>
                            <th scope="col">Name</th>
                            <th scope="col">Version</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="installationTbody">{$modhtml}</tbody>
                </table>
                <div>
                    <div class="col-sm-12 hxmwBttnGrp">
                        <button disabled class="btn btn-success btn-sm" onclick="installUpgradeModules(\'upgrade\');" id="btn-upgrade">Upgrade Selected <i class="fas fa-arrow-right"></i></button>
                        <div class="text-warning hxmwSpin" id="upgrade-div">
                            <i class="fas fa-spinner fa-spin"></i>
                            <span id="upgrade-notice" >Please wait, Upgrading x </span>
                        </div>
                    </div>
                </div>
            HTML;
        }
        if ($this->type === Module::STATUS_NOTINSTALLED) {
            return <<<HTML
                    <table class="table table-bordered table-condensed hxmwTable">
                    <thead>
                        <tr>
                            <th scope="col"><input onChange="selectUnselectCheckboxs(this, \'install\');" type="checkbox" class="form-check-input" id="checkall"></th>
                            <th scope="col">Name</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="notActiveOrInstalled">{$modhtml}</tbody>
                </table>
                <div>
                    <div class="col-sm-12 hxmwBttnGrp">
                        <button disabled class="btn btn-success btn-sm" onclick="installUpgradeModules(\'install\');" id="btn-install">Install Selected <i class="fas fa-arrow-right"></i></button>
                        <div class="text-warning hxmwSpin" id="installation-div">
                            <i class="fas fa-spinner fa-spin"></i>
                            <span id="installation-notice" >Please wait, Installing x </span>
                        </div>
                    </div>
                </div>
            HTML;
        }
        if ($this->type === Module::STATUS_DEPRECATED) {
            return <<<HTML
                <table class="table table-bordered table-condensed hxmwTable">
                    <thead>
                        <tr>
                            <th scope="col"><input onChange="selectUnselectCheckboxs(this, \'deinstall\');" type="checkbox" class="form-check-input" id="checkallRemove"></th>
                            <th scope="col">Name</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="deprecated">{$modhtml}</tbody>
                </table>
                <div>
                    <div class="col-sm-12 hxmwBttnGrp">
                        <button disabled class="btn btn-success btn-sm" onclick="deinstallModules();" id="btn-deinstall">Remove Selected <i class="fas fa-arrow-right"></i></button>
                        <div class="text-warning hxmwSpin" id="deinstallation-div">
                            <i class="fas fa-spinner fa-spin"></i>
                            <span id="removal-notice" >Please wait, Removing x </span>
                        </div>
                    </div>
                </div>
            HTML;
        }

        /*
                                {foreach $deprecated as $module}
                            <tr>
                                <td>
                                    <input type="checkbox" class="deinstall-checkbox" onChange="checkboxChange(this, \'deinstall\');" id="{$module->getWHMCSModuleId()}">
                                </td>
                                <td>{$module->getName()}</td>
                                <td>
                                    {if $module->isDeprecated()}
                                        <span class="textred small">Deprecated</span>
                                    {elseif $module->isInactive()}
                                        <span class="textorange small">Not Activated</span>
                                    {elseif $module->isActive()}
                                        <span class="textorange small">Activated/Installed</span>
                                    {else}
                                        <span class="textorange small">Not Installed</span>
                                    {/if}
                                </td>
                                <td>
                                    {if $module->isActive() || $module->isInactive()}//not installed
                                        <button class="btn btn-danger btn-xs removebtn" m-status="module.status wtf?" m-action="removeModule" m-type="{$module->getType()}" module="{$module->getWHMCSModuleId()}" data-toggle="tooltip" data-placement="top" title="Remove">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    {/if}
                                    {if $module->isStandardDeprecation()}
                                        <button class="btn btn-warning btn-xs toggleDetailsView" m-type="{$module->getWHMCSModuleId()}-details" data-toggle="tooltip" data-placement="top" title="Show Details">
                                            <i class="fas fa-caret-down"></i>
                                        </button>
                                    {/if}
                                </td>
                            </tr>
                            {if !$module->isStandardDeprecation()}
                                <tr>
                                    <td id="{$module->getWHMCSModuleId()}-details" class="bg-warning" colspan="4" style="display: none;">
                                        {$module->getDeprecationgNotice()}
                                        <!-- TODO
                                        if $module->isProductDeprecation()
                                            $module->getDeprecationgNotice().
                                            Read more: <a href="$module.url" target=_blank>here.</a>
                                            if $module.replacement
                                            Replacement available: $module.replacement.
                                        else
                                            Deprecated since WHMCS $module.whmcs_version. 
                                            $module.notice
                                            Read more: <a href="$module.url" target=_blank>here.</a>
                                            if $module.replacement
                                                Replacement available: $module.replacement.
                                            /if
                                        /if
                                        -->
                                    </td>
                                </tr>
                            {/if}
                        {/foreach}
        */
            /*
                {foreach $notinstalled as $module}
                    <tr>
                        <td>
                            {if !$module->isActive()}
                                <input type="checkbox" class="install-checkbox" onChange="checkboxChange(this, \'install\');" id="{$module->getWHMCSModuleId()}">
                            {/if}
                        </td>
                        <td>{$module->getName()}</td>
                        {if $module->isInactive()}
                            <td class="textred small">Not Active</td>
                            <td>
                                <button class="btn btn-default btn-xs" onclick="window.open(\'{$module->getDocumentationLink()}\');" data-toggle="tooltip" data-placement="top" title="See documentation">
                                    <i class="fas fa-book"></i>
                                </button>
                                <button class="btn btn-success btn-xs activatebtn" m-action="activateModule" m-type="{$module->getType()}" module="{$module->getWHMCSModuleId()}" data-toggle="tooltip" data-placement="top" title="Activate">
                                    <i class="fas fa-check"></i>
                                </button>
                            </td>
                        {else}
                            <td class="textred small">Not Installed</td>
                            <td>
                                <button class="btn btn-default btn-xs" onclick="window.open(\'{$module->getDocumentationLink()}\');" data-toggle="tooltip" data-placement="top" title="See documentation">
                                    <i class="fas fa-book"></i>
                                </button>
                            </td>
                        {/if}
                    </tr>
                {foreachelse}
                    <tr>
                        <td colspan="4">
                            <span class="text-center">No modules found.</span>
                        </td>
                    </tr>
                {/foreach}
             */
        
        return "";
    }

    public function getName() {
        return $this->name;
    }

    public function getSize() {
        return $this->size;
    }

    public function add($module) {
        $this->modules[] = $module;
        //sort them by priority
        usort($this->modules, "self::orderByPriority");
        $this->size++;
    }

    public function isEmpty() {
        return ($this->size === 0);
    }
    // TODO iterator

    public function getModules() {
        return $this->modules;
    }

    private static function orderByPriority($moda, $modb) {
        $prioa = $moda->getPriority();
        $priob = $modb->getPriority();
    
        if ($prioa === $priob) {
            return 0;
        }
        return ($prioa < $priob) ? 1 : -1;
    }
}

class Module {
    const STATUS_ACTIVE = "active";
    const STATUS_INACTIVE = "inactive";
    const STATUS_INSTALLED = "installed";
    const STATUS_NOTINSTALLED = "notinstalled";
    const STATUS_DEPRECATED = "deprecated";
    const VERSION_UNKNOWN = "n/a";
    
    protected $whmcsid = "";
    protected $data = [];
    protected static $brandregex = "/^ispapi/i";

    public function __construct($moduleid, $rawData) {
        $this->whmcsid = $moduleid;
        $this->loadData($rawData);
    }

    public function getHTML() {
        return '<tr>
            <td>
                {if !$m->isDeprecated() && $m->isUsedVersionOutdated()}
                <input type="checkbox" class="upgrade-checkbox" onChange="checkboxChange(this, \'upgrade\');" id="{$m->getWHMCSModuleId()}"/>
                {/if}
            </td>
            <td>{$m->getName()}</td>
            <td>
                {if $m->isDeprecated()}
                    <span class="textred small">{ucfirst($m->getStatus())}</span>
                {else}
                    <span class="text{if $m->isUsedVersionOutdated()}red{else}green{/if} small">v{$m->getUsedVersion()}</span>
                {/if}
            </td>
            <td>
                <!-- TODO: move button event listeners to jscript block -->
                <!-- TODO: replace by link button -->
                <button class="btn btn-default btn-xs" onclick="window.open(\'{$m->getDocumentationLink()}\');" data-toggle="tooltip" data-placement="top" title="See documentation">
                        <i class="fas fa-book"></i>
                </button>
                {$additionalHTML}
            </td>
        </tr>';
    }
    
    public function isStandardDeprecation() {
        return (
            ($this->data["status"] === self::STATUS_DEPRECATED)
            && $this->data[self::STATUS_DEPRECATED]["case"] !== "default"
        );
    }

    /**
     * Check if Module is activated
     * @return bool
     */
    public function isActive() {
        return ($this->data["status"] === self::STATUS_ACTIVE);
    }

    /**
     * Check if Module is inactive
     * @return bool
     */
    public function isInactive() {
        return ($this->data["status"] === self::STATUS_INACTIVE);
    }

    /**
     * Check if Module is deprecated
     * @return bool
     */
    public function isDeprecated() {
        return ($this->data["status"] === self::STATUS_DEPRECATED);
    }

    /**
     * Get WHMCS Module ID
     * @return string
     */
    public function getWHMCSModuleId() {
        return $this->whmcsid;
    }
    /**
     * Get Module Status
     * @return string
     */
    public function getStatus() {
        return self::STATUS_DEPRECATED;
    }

    /**
     * Get Module Type Identifier
     * @return string
     */
    public function getType() {
        return $this->data["type"];
    }

    /**
     * Get Module's Priority
     * @return int
     */
    public function getPriority() {
        return $this->data["prio"];
    }

    /**
     * Get Module's Used Version
     * @return string
     */
    public function getUsedVersion() {
        return $this->data["version_used"];
    }

    /**
     * Get Module's friendly Name
     * @return string
     */
    public function getName() {
        return $this->data["name"];
    }
    // TODO: magic getters!?

    /**
     * get module data
     * @param string $moduletype whmcs module status (active, not-active, not-installed)
     */
    protected function loadData($rawData)
    {
        // load basic module data from map
        $this->data = $rawData;
        $d = $rawData;

        // check deprecation
        if (
            isset($d[self::STATUS_DEPRECATED])
            && $d[self::STATUS_DEPRECATED]["case"] === "whmcs"
        ) {
            $flag = preg_match("/^(\d+\.\d+.\d+).*$/", $GLOBALS["CONFIG"]["Version"], $m);
            if (
                $flag
                && version_compare($d[self::STATUS_DEPRECATED]["whmcs_version"], $m[1], ">=")
            ) {
                // compared whmcs_version given for the deprecation with the one in use
                // not matching accordingly -> not deprecated!
                unset($d[self::STATUS_DEPRECATED]);
            }
        }

        // get the status in WHMCS
        $this->data["status"] = $this->getStatus();
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_TIMEOUT => 3,
            CURLOPT_HEADER => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_USERAGENT => 'ISPAPI MODULES WIDGET',
            CURLOPT_URL => $this->getReleaseFileLink()
        ]);
        $cdata = curl_exec($ch);
        curl_close($ch);
        if ($cdata) {
            //404 could happen and will be returned as string
            $cdata = json_decode($cdata, true);
        }
        
        $this->data = array_merge($this->data, [
            "version_latest" => $cdata ? $cdata["version"] : self::VERSION_UNKNOWN,
            "version_used" => self::VERSION_UNKNOWN
        ]);
    }

    /**
     * check if latest version is unknown
     * @return bool
     */
    public function isLatestVersionUnknown() {
        return ($this->data["version_latest"] === self::VERSION_UNKNOWN);
    }

    /**
     * check if used version is unknown
     * @return bool
     */
    public function isUsedVersionUnknown() {
        return ($this->data["version_used"] === self::VERSION_UNKNOWN);
    }

    /**
     * check if used version is outdated
     * @return bool
     */
    public function isUsedVersionOutdated() {
        if (
            $this->isLatestVersionUnknown()
            || $this->isUsedVersionUnknown()
        ){
            return false;
        }
        return version_compare(
            $this->data["version_used"],
            $this->data["version_latest"],
            "<"
        );
    }

    /**
     * get the uri to the raw version of the release.json file
     * @return string
     */
    private function getReleaseFileLink() {
        $id = $this->data["repoid"];
        return "https://raw.githubusercontent.com/hexonet/$id/master/release.json";
    }

    /**
     * get the uri to the module documentation
     * @return string
     */
    public function getDocumentationLink() {
        $id = $this->data["repoid"];
        return "https://centralnic-reseller.github.io/centralnic-reseller/docs/hexonet/whmcs/$id";
    }

    /**
     * get the uri to the GitHub repository
     * @return string
     */
    public function getGitHubLink() {
        $id = $this->data["repoid"];
        return "https://github.com/hexonet/$id";
    }

    /**
     * get the uri to the latest version archive
     * @return string
     */
    public function getDownloadLink() {
        $id = $this->data["repoid"];
        return ($this->getGitHubLink() . "/raw/master/$id-latest.zip");
    }

    /**
     * Delete a given folder recursively
     * @param string $dir current directory
     * @param array $results results container
     * @return array
     */
    private function delTree($dir, $results)
    {
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $fullpath = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($fullpath)) {
                // var_dump($fullpath);
                $results = $this->delTree($fullpath, $results);
            } else {
                $results[$fullpath] = unlink($fullpath);
            }
        }
        $results[$dir] = rmdir($dir);
        return $results;
    }

    /**
     * Check access rights for directory removal
     * @param string $dir current directory
     * @param array $results results container
     * @return array
     */
    private function checkDirAndFileRemovable($dir, $results)
    {
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $fullpath = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($fullpath)) {
                $results = $this->checkDirAndFileRemovable($fullpath, $results);
            } else {
                // check if files are removable
                $results[$fullpath] = is_writable(dirname($fullpath));
            }
        }
        // check if the directory is removable
        $results[$dir] = is_writable(dirname($dir));
        return $results;
    }

    /**
     * Check process results
     * @param array $results results to check
     * @return array
     */
    private function checkResults($results)
    {
        foreach ($results as $key => $value) {
            if ($value == false) {
                return [
                    "result" => false,
                    "msg" => $key . " Permission Denied"
                ];
            }
        }
        return [
            "result" => true,
            "msg" => "success"
        ];
    }

    /**
     * Remove the module
     * @return array
     */
    public function remove() {
        // TODO sending the below as json response (header set for this)!
        $result = [];
        $dirs = $this->data['cleanup_files'];
        if (empty($dirs)) {
            return [
                "success" => false,
                "data" => "No files were found!"
            ];
        }
        try {
            // check if files in all dirs are removable
            foreach ($dirs as $dir) {
                $dir_files = $this->checkDirAndFileRemovable(ROOTDIR . $dir, []);
                // the check permission
                $permission_check = $this->checkResults($dir_files);
                if ($permission_check['result'] === false) {
                    return [
                        "success" => false,
                        "data" => $permission_check['msg']
                    ];
                }
            }
            // when files are removable, then delete them
            $all_delete_files = [];
            foreach ($dirs as $dir) {
                $delete_results = $this->delTree(ROOTDIR . $dir, []);
                // add deleted files, in case the user want to see them
                $all_delete_files[$dir] = $delete_results;
                // check if files were deleted
                $permission_check = $this->checkResults($delete_results);
                if ($permission_check['result'] === false) {
                    return [
                        "success" => false,
                        "data" => $permission_check['msg']
                    ];
                }                
            }
        } catch (Exception $e) {
            return [
                "success" => false,
                "data" => $e //TODO $e->getMessage() !?
            ];
        }
        return [
            "success" => true,
            "data" => $all_delete_files
        ];
    }


    /**
     * Upgrade the module
     * @return array
     */
    public function upgrade() {
        // if upgrade check the permission
        // TODO: doesn't it also make sense for installation process?
        $dirs = $this->data['install_files'];
        foreach ($dirs as $dir) {
            $dir_files = $this->checkDirAndFileRemovable(ROOTDIR . $dir, []);
            // the check permission
            $permission_check = $this->checkResults($dir_files);
            if ($permission_check['result'] === false) {
                return [
                    "success" => false,
                    "data" => $permission_check['msg']
                ];
            }
        }
        // fallback to installation
        return $this->install();
    }

    /**
     * Install the module
     * @return array
     */
    public function install() {
        // download latest zip from github repo
        $success = false;
        $dirs = $this->data['install_files'];
        $zipfile = ROOTDIR . tempnam(sys_get_temp_dir(), 'zipfile') . $this->data['repoid'] . "-latest.zip";
        // download data from url
        $download = file_put_contents($zipfile, fopen($this->getDownloadLink(), 'r'));
        if ($download > 0) {
            // extract zip file
            $zip = new ZipArchive();
            $res = $zip->open($zipfile);
            if ($res) {
                $entries = [];
                foreach ($dirs as $dir) {
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $filename = $zip->getNameIndex($i);
                        $fileinfo = pathinfo($filename);
                        if (($fileinfo['extension'] != null) && str_starts_with(DIRECTORY_SEPARATOR . $filename, $dir)) {
                            $entries[] = $filename;
                        }
                    }
                }
                // extract files
                if ($zip->extractTo(ROOTDIR . DIRECTORY_SEPARATOR, $entries)) {
                    $success = true;
                }
            }
            $zip->close();
        }
        unlink($zipfile);

        // TODO sending the below as json response (header set for this)!
        if ($success) {
            return [
                "success" => true,
                "module" => $module,
                "result" => 'success'
            ];
        }
        return [
            "success" => false,
            "module" => $module,
            "result" => 'Error in ' . $module . ': Failed to download zip file.'
        ];
    }


    /**
     * Deactivate the module
     * @return array
     */
    public function deactivate() {
        $results = localAPI('DeactivateModule', [
            'moduleName' => $module,
            'moduleType' => $type
        ]);
        // todo - check the response
        // TODO sending the below as json response (header set for this)!
        return [
            "success" => true,
            "type" => $type,
            "module" => $module,
            "result" => $results
        ];
    }


    /**
     * Activate the module
     * @return array
     */
    public function activate() {
        $results = localAPI('ActivateModule', [
            'moduleName' => $module,
            'moduleType' => $type,
        ]);
        // TODO - check the response
        // TODO sending the below as json response (header set for this)!
        return [
            "success" => true,
            "module" => $module,
            "type" => $type,
            "result" => $results
        ];
    }
}

class Registrar extends Module {
    public function getHTML() {
        if ($this->isDeprecated()) {
            
        }
        return '<tr>
            <td>
                {if !$m->isDeprecated() && $m->isUsedVersionOutdated()}
                <input type="checkbox" class="upgrade-checkbox" onChange="checkboxChange(this, \'upgrade\');" id="{$m->getWHMCSModuleId()}"/>
                {/if}
            </td>
            <td>{$m->getName()}</td>
            <td>
                {if $m->isDeprecated()}
                    <span class="textred small">{ucfirst($m->getStatus())}</span>
                {else}
                    <span class="text{if $m->isUsedVersionOutdated()}red{else}green{/if} small">v{$m->getUsedVersion()}</span>
                {/if}
            </td>
            <td>
                <!-- TODO: move button event listeners to jscript block -->
                <!-- TODO: replace by link button -->
                <button class="btn btn-default btn-xs" onclick="window.open(\'{$m->getDocumentationLink()}\');" data-toggle="tooltip" data-placement="top" title="See documentation">
                        <i class="fas fa-book"></i>
                </button>
                {$additionalHTML}
            </td>
        </tr>';


        $addhtml = '<button class="btn btn-danger btn-xs deactivatebtn" m-action="deactivateModule" m-type="{$m->getType()}" module="{$m->getWHMCSModuleId()}" data-toggle="tooltip" data-placement="top" title="Deactivate">
                <i class="fas fa-minus-square"></i>
            </button>';

        $smarty = new \WHMCS\Smarty(true);
        $smarty->assign("m", $this);
        $smarty->assign("additionalHTML", $addhtml);

        return $smarty->fetch('string: ' . parent::getHTML());    
    }
    protected function loadData($rawData) {
        parent::loadData($rawData);
        $v = constant(strtoupper($this->whmcsid) . "_MODULE_VERSION");
        if (!is_null($v)) {
            $this->data["version_used"] = $v;
        }
    }
    /**
     * Get Module Status
     * @return string
     */
    public function getStatus() {
        if (isset($this->data[self::STATUS_DEPRECATED])) {
            return self::STATUS_DEPRECATED;
        }

        $cl = "\\WHMCS\\Module\\" . ucfirst($this->data["type"]);
        $obj = new $cl;
        $mods = preg_grep(self::$brandregex, $obj->getList());
        if (!$obj->load($this->whmcsid)){
            return self::STATUS_NOTINSTALLED;
        }
        return ($obj->isActivated()) ?
            self::STATUS_ACTIVE :
            self::STATUS_INACTIVE;
    }
}

class Addon extends Module {
    public function getHTML() {
        $addhtml = '<button class="btn btn-danger btn-xs deactivatebtn" m-action="deactivateModule" m-type="{$m->getType()}" module="{$m->getWHMCSModuleId()}" data-toggle="tooltip" data-placement="top" title="Deactivate">
                <i class="fas fa-minus-square"></i>
            </button>';

        $smarty = new \WHMCS\Smarty(true);
        $smarty->assign("m", $this);
        $smarty->assign("additionalHTML", $addhtml);

        return $smarty->fetch('string: ' . parent::getHTML());
    
    }
    protected function loadData($rawData) {
        parent::loadData($rawData);
        $fn = $this->whmcsid . "_config";
        if (is_callable($fn)) {
            $tmp = call_user_func($fn);
            if (isset($tmp["version"])) {
                $this->data["version_used"] = $tmp["version"];
            }
        }
    }
    /**
     * Get Status of the given $addon
     * @param string $module the whmcs module identifier e.g. ispapidpi
     * @param string $data module data (we need access to key deprecated)
     * @return string
     */
    public function getStatus() {
        global $CONFIG;
        static $activeaddons = null;

        if (isset($this->data[self::STATUS_DEPRECATED])) {
            return self::STATUS_DEPRECATED;
        }

        $cl = "\\WHMCS\\Module\\" . ucfirst($this->data["type"]);
        $obj = new $cl;
        $mods = preg_grep(self::$brandregex, $obj->getList());
        if (!$obj->load($this->whmcsid)){ // necessary for version access
            return self::STATUS_NOTINSTALLED;
        }
        if (is_null($activeaddons)){
            $activeaddons = explode(",", $CONFIG["ActiveAddonModules"]);
        }
        return (in_array($this->whmcsid, $activeaddons)) ?
            self::STATUS_ACTIVE :
            self::STATUS_INACTIVE;
    }
}

class Widget extends Module {
    public function getHTML() {
        $smarty = new \WHMCS\Smarty(true);
        $smarty->assign("m", $this);
        $smarty->assign("additionalHTML", "");
        return $smarty->fetch('string: ' . parent::getHTML());
    }

    protected function loadData($rawData) {
        parent::loadData($rawData);
        $tmp = explode("widget", $this->whmcsid);
        $cl = "\\WHMCS\\Module\\Widget\\" . ucfirst($tmp[0]) . ucfirst($tmp[1]) . "Widget";
        //NOTE: 2nd param in class_exists deactivates autoload!
        //otherwise we run into php warning for non-installed widgets
        if (class_exists($cl, false) && defined("$cl::VERSION")) {
            $this->data["version_used"] = $cl::VERSION;
        }
    }
    /**
     * Get Module Status
     * @return string
     */
    public function getStatus() {
        if (isset($this->data[self::STATUS_DEPRECATED])) {
            return self::STATUS_DEPRECATED;
        }

        // TODO: might we always set $this->whmcsid to the str_replace val?
        $path = implode(DIRECTORY_SEPARATOR, [
            ROOTDIR,
            "modules",
            "widgets",
            str_replace("widget", "_", $this->whmcsid) . ".php"
        ]);
        return (file_exists($path)) ?
            self::STATUS_ACTIVE :
            self::STATUS_NOTINSTALLED;
    }
}

add_hook('AdminHomeWidgets', 1, function () {
    return new IspapiModulesWidget();
});

add_hook('AdminAreaHeadOutput', 1, function($vars) {
    //die("<pre>" . print_r($vars, true) . "</pre>");
    if ($vars["pagetitle"] === "Dashboard") {
        return <<<HTML
            <style>
            .hxwmTabs {
                border-radius:50%;
                padding: 0px 5px 0px 5px;
            }
            .hxmwTable {
                margin-top: 4px;
                margin-bottom: 10px;
            }
            .hxmwTable th:nth-of-type(1) {
                width: 5%;
            }
            .hxmwTable th:nth-of-type(2) {
                width: 35%;
            }
            .hxmwTable th:nth-of-type(3),
            .hxmwTable th:nth-of-type(4) {
                width: 30%;
            }
            .hxmwBttnGrp {
                display: inline-flex;
                padding: 0px;
            }
            .hxmwSpin {
                display:none;
                padding: 7px 0px 0px 10px;
                font-size: 10px;
            }
            </style>
            <script>
            const loadingIcon = '<i class="fas fa-spinner fa-spin"></i>';
            
            // activate/deactivate logic
            function hxmwActivateDeactivateHandler(event) {
                // set loading icon
                $(this).html(loadingIcon);
                const defaultIcon = $(this).html();
                // prepare data
                const type = $(this).attr("m-type");
                const module = $(this).attr("module");
                const token = csrfToken; // available in six, twenty-one
                const action = $(this).attr("m-action");
                // if registrar: user internal API
                if (type == 'registrar'){
                    activateDeactivate(type, module, action, token).then(function(result){
                        if ( result.success ){
                            refreshWidget('IspapiModulesWidget', 'refresh=1');
                        }
                        else{
                            const msg = 'An error occured, couldn\'t activate module: ' + module;
                            // Add response in Modal body
                            $('.modal-body-alert').html(msg);
                            // Display Modal
                            $('#alertModalOther').modal('show');
                        }
                    });
                }
                else if (type == 'addon'){
                    activateDeactivate(type, module, action, token).then(function(result){
                        if ( result ){
                            refreshWidget('IspapiModulesWidget', 'refresh=1');
                        }
                        else{
                            const msg = 'An error occured, couldn\'t activate module: ' + module;
                            // Add response in Modal body
                            $('.modal-body-alert').html(msg);
                            // Display Modal
                            $('#alertModalOther').modal('show');
                        }
                    });
                }
                else{
                    const msg = type + ' not supported';
                    // Add response in Modal body
                    $('.modal-body-alert').html(msg);
                    // Display Modal
                    $('#alertModalOther').modal('show');
                }
            }
            // toggle the details view
            function hxmwToggleDetailsHandler(event) {
                const module = $(this).attr("m-type");
                $("#"+module).fadeToggle();
                $(this).children('.fa-caret-up, .fa-caret-down').toggleClass("fa-caret-up fa-caret-down");
            }
            // handler for module removal
            function hxmwRemovalHandler(event) {
                const loadingIcon = '<i class="fas fa-spinner fa-spin"></i>';
                const defaultIcon = $(this).html();
                // set loading icon
                $(this).html(loadingIcon);
                // prepare data
                const type = $(this).attr("m-type");
                const module = $(this).attr("module");
                const token = $(this).attr("token");
                const status = $(this).attr("m-status");
                if ( status === 'active'){
                    // deactivate the module
                    activateDeactivate(type, module, 'deactivate', token).then(function(result){
                        if ( result ){
                            // remove from the system
                            removeModule(module).then(function(result){
                                if (result.success){
                                    refreshWidget('IspapiModulesWidget', 'refresh=1');
                                    return true;
                                }
                                else {
                                    const msg = "could not remove module: " + module;
                                    // Add response in Modal body
                                    $('.modal-body').html(msg);
                                    // Display Modal
                                    $('#alertModalOther').modal('show');
                                }
                            })
                        }
                        else{
                            const msg = 'An error occured, couldn\'t activate module: ' + module;
                            // Add response in Modal body
                            $('.modal-body').html(msg);
                            // Display Modal
                            $('#alertModalOther').modal('show');
                        }
                    });
                }
                else {
                    // remove from the system
                    removeModule(module).then(function(result){
                        if (result.success){
                            const data = JSON.parse(result.widgetOutput);
                            if(data.success){
                                var flag_failed = false;
                                var deleted_files= "";
                                var failed_files= "";
                                // console.log(data.data);
                                for (const [key, value] of Object.entries(data.data)) {
                                    for (const [subkey, subvalue] of Object.entries(value)) {
                                        console.log(key, subvalue);
                                        if (subvalue == true){
                                            deleted_files += subkey + "\\n";
                                        } else {
                                            flag_failed = true;
                                            failed_files += subkey + "\\n";
                                        }
                                    }
                                }
                                if(flag_failed){
                                    const msg = "Operations failed with error: \\n files failed to delete: \\n " + failed_files;
                                    // Add response in Modal body
                                    $('.modal-body').html(msg);
                                    // Display Modal
                                    $('#alertModal').modal('show'); 
                                }
                                else{
                                    const msg = "Operation completed with Success!";
                                    // Add response in Modal body
                                    $('.modal-body').html(msg);
                                    // Display Modal
                                    $('#alertModal').modal('show');
                                }
                            }
                            else {
                                const msg = "An error occured on server side: \\n\\n" + data.data;
                                // Add response in Modal body
                                $('.modal-body').html(msg);
                                // Display Modal
                                $('#alertModal').modal('show');
                            }
                        }
                        else{
                            const msg = "Server error, check your internet connection.";
                            // Add response in Modal body
                            $('.modal-body').html(msg);
                            // Display Modal
                            $('#alertModal').modal('show');
                        }
                        //refreshWidget('IspapiModulesWidget', 'refresh=1');
                    })
                }
            }
            async function removeModule(module){
                const url = WHMCS.adminUtils.getAdminRouteUrl('/widget/refresh&widget=IspapiModulesWidget&module='+ module + '&action=removeModule');
                    const result = await $.ajax({
                        url: url,
                        type: 'GET',
                        success: function (data) { return true;},
                        error: function (jqXHR, textStatus, errorThrown) { return false; }
                    });
                    return result;
            }
            async function activateDeactivate(type, module, action, token = 0){
                if (type == 'registrar'){
                    const url = WHMCS.adminUtils.getAdminRouteUrl('/widget/refresh&widget=IspapiModulesWidget&module='+ module + '&type=' + type + '&action=' + action);
                    const result = await $.ajax({
                        url: url,
                        type: 'GET',
                        data: {},
                        datatype: 'json'
                    });
                    return result;
                }
                else if( type == 'addon'){
                    const url= '/admin/configaddonmods.php?action='+ action +'&module=' + module + token;
                    const result = await $.ajax({
                        url: url,
                        type: 'GET',
                        data: {},
                        datatype: 'json'
                    })
                    return result;
                }
                else{
                    return false;
                }
            }
            async function selectUnselectCheckboxs(selector, operation_type){
                var checkboxes = operation_type == 'install'? $('tbody#notActiveOrInstalled input:checkbox') : $('tbody#installationTbody input:checkbox');
                if($(selector).is(':checked')) {
                    for(const checkbox of checkboxes) {
                        $(checkbox).prop('checked', true);
                        module_id = $(checkbox).attr('id');
                        let result = await checkDependency(module_id, 'select');
                    }
                }
                else{
                    for(const checkbox of checkboxes) {
                        $(checkbox).removeAttr('checked');
                        module_id = $(checkbox).attr('id');
                        let result = await checkDependency(module_id, 'unselect');
                    }
                }
                enableDisableBtn(operation_type);
            }
            async function checkboxChange(reference, operation_type){
                let module_id = $(reference).attr('id');
                if($(reference).is(':checked')) {
                    checkDependency(module_id, 'select');
                }
                else{
                    checkDependency(module_id, 'unselect');
                }
                // operation button check
                enableDisableBtn(operation_type);
            }
            async function enableDisableBtn(operation_type){
                let checkboxs = [];
                let referenceBtn = undefined;
                if (operation_type == 'install'){
                    checkboxs = $('.install-checkbox:checkbox:checked');
                    referenceBtn = $('#btn-install');
                }
                else{
                    checkboxs = $('.upgrade-checkbox:checkbox:checked');
                    referenceBtn = $('#btn-upgrade');
                }
                if(checkboxs.length == 0){
                    referenceBtn.prop('disabled', true);
                }
                else{
                    referenceBtn.prop('disabled', false);
                }
            }
            async function installUpgradeModules(operation){
                let modules = [];
                let success = true;
                let checkboxs =  operation == 'install'? $('.install-checkbox:checkbox:checked') : $('.upgrade-checkbox:checkbox:checked');
                for (const checkbox of checkboxs){
                    // get module id from the checkbox
                    let module = $(checkbox).attr('id');
                    // install the module
                    let result = await installSingleModule(module, operation);
                    if (typeof result != "boolean"){
                        success = false;
                        $('.modal-body-alert').html(result);
                        $('#alertModalOther').modal('show');
                        operation == 'install'? $('#installation-div').slideUp(100) : $('#upgrade-div').slideUp(100);
                    }
                }
                if (success){
                    const msg = operation == 'install'? "Installation finished successfully!" : "Upgrade finished successfully!";
                    $('.modal-body').html(msg);
                    $('#alertModal').modal('show');
                }
            }
            async function installSingleModule(module_id, operation){
                // show & update notification message
                operation == 'install'? $('#installation-div').slideDown(500) : $('#upgrade-div').slideDown(500);
                operation == 'install'? $('#installation-notice').html('Please wait, installing: ' + module_id) : $('#upgrade-notice').html('Please wait, upgrading: ' + module_id);
                // send xhr request
                const url = WHMCS.adminUtils.getAdminRouteUrl('/widget/refresh&widget=IspapiModulesWidget&module='+ module_id + '&action=' + operation +'Module');
                const result = await $.ajax({
                    url: url,
                    type: 'GET',
                    success: function (data) { return true;},
                    error: function (jqXHR, textStatus, errorThrown) { return false; }
                });
                // hide notification message
                operation == 'install'? $('#installation-div').slideUp(100) : $('#upgrade-div').slideUp(100);
                // check results
                const data = JSON.parse(result.widgetOutput);
                if (data.success){
                    return true;
                }
                else{
                    const msg = data.result;
                    return msg;
                }
            }
            async function checkDependency(module_id, mode){
                const dependency_list = dependency_map[module_id];
                console.log(dependency_list);
                // check if the module have at least one dependecy
                if (dependency_list != undefined){
                    for (var key in dependency_list) {
                        if(dependency_list[key] == false){
                            if(mode == 'select'){
                                $('#'+key).prop({'checked':true, 'disabled': true});
                            }
                            else{
                                $('#'+key).prop({'checked':false, 'disabled': false});
                            }
                        }
                    }
                }
                return true;
            }
            function hxmwRefreshWidgetHandler() {
                refreshWidget('IspapiModulesWidget', 'refresh=1');
            }
            </script>
        HTML;
    }
});
