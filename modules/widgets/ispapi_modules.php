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

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
namespace WHMCS\Module\Widget;

use App;
use WHMCS\Config\Setting;

/**
 * ISPAPI Modules Widget.
 */
class IspapiModulesWidget extends \WHMCS\Module\AbstractWidget
{
    const VERSION = "2.3.1";

    protected $title = 'HEXONET Modules Overview';
    protected $description = '';
    protected $weight = 150;
    protected $columns = 1;
    protected $cache = false;
    protected $cacheExpiry = 120;
    protected $requiredPermission = '';
    public static $widgetid = "IspapiModulesWidget";
    public static $sessionttl = 24 * 3600; // id

    /**
     * Fetch data that will be provided to generateOutput method
     * @return array|null data array or null in case of an error
     */
    public function getData()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $id = self::$widgetid;

        // status toggle
        $status = \App::getFromRequest("status");
        if ($status !== "") {
            $status = (int)$status;
            if (in_array($status, [0,1])) {
                Setting::setValue($id . "status", $status);
            }
        }

        // hidden widgets -> don't load data
        $isHidden = in_array($id, $this->adminUser->hiddenWidgets);
        if ($isHidden) {
            return [
                "status" => 0,
                "widgetid" => $id
            ];
        }

        // load data
        $status = Setting::getValue($id . "status");
        $data = [
            "status" => is_null($status) ? 1 : (int)$status,
            "widgetid" => $id
        ];

        // inactive module or missing dependency
        if ($data["status"] !== 1) {
            return $data;
        }

        if (
            !empty($_REQUEST["refresh"]) // refresh request
            || !isset($_SESSION[$id]) // Session not yet initialized
            || (time() > $_SESSION[$id]["expires"]) // data cache expired
        ) {
            $_SESSION[$id] = [
                "expires" => time() + self::$sessionttl,
                "ttl" =>  + self::$sessionttl
            ];
        }
        return array_merge($data, [
            "groups" => IspapiModuleFactory::getModuleGroups()
        ]);
    }

    /**
     * generate widget's html output
     * @param array $data input data (from getData method)
     * @return string html code
     */
    public function generateOutput($data)
    {
        // widget controls / status switch
        // missing or inactive registrar Module
        if ($data["status"] === -1) {
            $html = <<<HTML
                <div class="widget-content-padded widget-billing">
                    <div class="color-pink">
                        Please install or upgrade to the latest HEXONET ISPAPI Registrar Module.
                        <span data-toggle="tooltip" title="The HEXONET ISPAPI Registrar Module is regularly maintained, download and documentation available at github." class="glyphicon glyphicon-question-sign"></span><br/>
                        <a href="https://github.com/hexonet/whmcs-ispapi-registrar">
                            <img src="https://raw.githubusercontent.com/hexonet/whmcs-ispapi-registrar/master/modules/registrars/ispapi/logo.png" width="125" height="40"/>
                        </a>
                    </div>
                </div>
            HTML;
        } else {
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
            $expires = $_SESSION[$data["widgetid"]]["expires"] - time();
            $ttl = $_SESSION[$data["widgetid"]]["ttl"];
            $ico = ($data["status"] === 1) ? "on" : "off";
            $wid = $data["widgetid"];
            $status = $data["status"];
            $html .= <<<HTML
            <script type="text/javascript">
            // fn shared with other widgets
            function hxRefreshWidget(widgetName, requestString, cb) {
                const panelBody = $('.panel[data-widget="' + widgetName + '"] .panel-body');
                const url = WHMCS.adminUtils.getAdminRouteUrl('/widget/refresh&widget=' + widgetName + '&' + requestString);
                panelBody.addClass('panel-loading');
                return WHMCS.http.jqClient.post(url, function(data) {
                    panelBody.html(data.widgetOutput);
                    panelBody.removeClass('panel-loading');
                }, 'json').always(cb);
            }
            if (!$("#panel${wid} .widget-tools .hx-widget-toggle").length) {
                $("#panel${wid} .widget-tools").prepend(
                    `<a href="#" class="hx-widget-toggle" data-status="${status}">
                        <i class=\"fas fa-toggle-${ico}\"></i>
                    </a>`
                );
            } else {
                $("a.hx-widget-toggle").data("status", {$status});
            }
            if (!$("#hxmodsexpires").length) {
                $("#panel${wid} .widget-tools").prepend(
                    `<a href="#" class="hx-widget-expires" data-expires="${expires}" data-ttl="${ttl}">
                        <span id="hxmodsexpires" class="ttlcounter"></span>
                    </a>`
                );
            }
            $("#hxmodsexpires")
                .data("ttl", {$ttl})
                .data("expires", {$expires})
                .html(hxSecondsToHms({$expires}, {$ttl}));
            if ($("#panel${wid} .hx-widget-toggle").data("status") === 1) {
                $("a.hx-widget-expires").show();
            } else {
                $("a.hx-widget-expires").hide();
            }
            $("#panel${wid} .hx-widget-toggle").off().on("click", function (event) {
                event.preventDefault();
                const icon = $(this).find("i[class^=\"fas fa-toggle-\"]");
                const mythis = this;
                const widget = $(this).closest('.panel').data('widget');
                const newstatus = (1 - $(this).data("status"));
                icon.attr("class", "fas fa-spinner fa-spin");
                hxRefreshWidget(widget, "refresh=1&status=" + newstatus, function(){
                    icon.attr("class", "fas fa-toggle-" + ((newstatus === 0) ? "off" : "on"));
                    $(mythis).data("status", newstatus);
                    packery.fit(mythis);
                    packery.shiftLayout();
                })
            });
            </script>
            HTML;
        }

        // Inactive Widget / Dependency Missing
        if ($data["status"] !== 1) {
            return $html;
        }

        // generate HTML
        $html .= IspapiModuleFactory::getHTML($data["groups"]);

        // Data Refresh Request -> avoid including JavaScript
        if (!empty($_REQUEST["refresh"])) {
            return $html;
        }

        return <<<HTML
            {$html}
            <script type="text/javascript">
            hxStartCounter("#hxmodsexpires");
            </script>
        HTML;
    }
}

class IspapiModuleFactory
{
    private static $map = [];

    //TODO: doesn't it make sense to deprecate the "type" parameter
    //for xhr requests as it is accessible by class?
    public static function getModule($moduleid, $data)
    {
        if (!isset(self::$map[$moduleid])) {
            return null;
        }
        $type = self::$map[$moduleid]["type"];
        // our module type in the widget namespace
        $cl = "\\WHMCS\\Module\\Widget\\Ispapi" . ucfirst($data["type"]);
        return new $cl($moduleid, $data);
    }

    public static function getModuleGroups()
    {
        if (!isset($_SESSION[IspapiModulesWidget::$widgetid]["MODULE_MAP"])) {
            self::$map = [];
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_TIMEOUT => 3,
                CURLOPT_HEADER => 0,
                CURLOPT_RETURNTRANSFER => 1,
                CURLOPT_USERAGENT => "ISPAPI MODULES WIDGET",
                CURLOPT_URL => "https://raw.githubusercontent.com/hexonet/whmcs-ispapi-widget-modules/master/ispapi_modules.json",
                CURLOPT_HTTPHEADER => ["Cache-Control: no-cache, must-revalidate"]
            ]);
            $cdata = curl_exec($ch);
            curl_close($ch);
            if ($cdata) {
                //404 could happen and will be returned as string
                // TODO Domainchecker deprecated check
                $cdata = json_decode($cdata, true);
                if (!is_null($cdata)) {
                    self::$map = $cdata;
                }
            }
            $_SESSION[IspapiModulesWidget::$widgetid]["MODULE_MAP"] = self::$map;
        }
        self::$map = $_SESSION[IspapiModulesWidget::$widgetid]["MODULE_MAP"];

        // TODO: why not directly using STATUS_INSTALLED as Group?
        // Status is still accessible by Module Instance
        foreach (self::$map as $module => $rawData) {
            $mod = self::getModule($module, $rawData);
            if (is_null($mod)) {
                continue;
            }
            if (
                !($mod->isDeprecated() && $mod->isUsedVersionUnknown()) // probably not installed
            ) {
                self::addToModuleGroup($mod);
            }
        }
        uasort(self::$moduleGroups, [ IspapiModuleGroup::class, "orderByPriority"]);
        return self::$moduleGroups;
    }

    public static function addToModuleGroup($module)
    {
        $type = $module->getStatus();
        $grp = self::getModuleGroup($type);
        $grp->add($module);
    }

    public static function getModuleGroup($type)
    {
        if (!isset(self::$moduleGroups[$type])) {
            self::$moduleGroups[$type] = new IspapiModuleGroup($type);
        }
        return self::$moduleGroups[$type];
    }

    public static function getHTML($groups)
    {
        // get required js code
        $jscript = self::generateOutputJS();
        $grphtml = "";

        foreach ($groups as $grp) {
            $grphtml .= $grp->getRows();
        }

        return <<<HTML
            <div class="widget-billing">
                <div class="row">
                    <div class="col-sm-12">
                        <table class="table table-condensed hxmwTable">
                        <thead>
                            <tr>
                                <th scope="col">Module</th>
                                <th scope="col">Status</th>
                                <th scope="col"></th><!-- icons -->
                                <th scope="col">Version used</th>
                                <th scope="col">latest</th>
                                <th scope="col">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                        {$grphtml}
                        </tbody>
                        </table>
                    </div>
                </div>
            </div>
            {$jscript}
        HTML;
    }

    private static function generateOutputJS()
    {
        return <<<HTML
            <script type="text/javascript">
                $('.toggleDetailsView').off().on('click', hxmwToggleDetailsHandler);
                $('#hxmoremods').off().on('click', hxmwToggleFurtherModulesHandler);
            </script>
        HTML;
    }

    private static $moduleGroups = [];
}

class IspapiModuleGroup
{
    private $size = 0;
    private $modules;
    private $name;
    private $type;
    private $prio;

    public function __construct($type)
    {
        $this->modules = [];
        $this->size = 0;
        $this->type = $type;
        switch ($type) {
            case IspapiModule::STATUS_NOTINSTALLED:
                $this->name = "Not Installed/Inactive";
                $this->prio = 0;
                break;
            case IspapiModule::STATUS_INSTALLED:
                $this->name = "Installed/Active";
                $this->prio = 2;
                break;
            case IspapiModule::STATUS_INACTIVE:
                $this->name = "Installed/Inactive";
                $this->prio = 3;
                break;
            case IspapiModule::STATUS_ACTIVE:
                $this->name = "Installed/Active";
                $this->prio = 4;
                break;
            default:
                $this->prio = 1;
                $this->name = ucfirst($type);
                break;
        }
    }

    public function getRows()
    {
        $modhtml = "";
        if ($this->isEmpty()) {
            return $modhtml;
        }
        if ($this->type === IspapiModule::STATUS_NOTINSTALLED) {
            $modhtml .= <<<HTML
                <tr>
                    <td colspan="6" align="right">
                        <button id="hxmoremods" class="btn btn-secondary btn-sm" data-selector="tr.mod-notinstalled">Further Modules we offer ...</button>
                    </td>
                </tr>
            HTML;
        }
        foreach ($this->modules as $m) {
            $modhtml .= $m->getHTML();
        }
        return $modhtml;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getSize()
    {
        return $this->size;
    }

    public function getPriority()
    {
        return $this->prio;
    }

    public function add($module)
    {
        $this->modules[] = $module;
        usort($this->modules, "self::orderByPriority");
        $this->size++;
    }

    public function isEmpty()
    {
        return ($this->size === 0);
    }

    public function getModules()
    {
        return $this->modules;
    }

    public static function orderByPriority($moda, $modb)
    {
        $prioa = $moda->getPriority();
        $priob = $modb->getPriority();

        if ($prioa === $priob) {
            return 0;
        }
        return ($prioa < $priob) ? 1 : -1;
    }
}

class IspapiModule
{
    const STATUS_ACTIVE = "Active";
    const STATUS_INACTIVE = "Inactive";
    const STATUS_INSTALLED = "Installed";
    const STATUS_NOTINSTALLED = "Not Installed";
    const STATUS_DEPRECATED = "Deprecated";
    const VERSION_UNKNOWN = "n/a";

    protected $whmcsid = "";
    protected $data = [];

    public function __construct($moduleid, $rawData)
    {
        $this->whmcsid = $moduleid;
        $this->loadData($rawData);
    }

    public function getDeprecationNotice()
    {
        if ($this->isStandardDeprecation()) {
            return "";
        }

        $d = $this->data[strtolower(IspapiModule::STATUS_DEPRECATED)];

        // deprecated since specific WHMCS Release
        // WHMCS ships now this addon's functionality
        // or
        // deprecated Product / no longer offered
        $html = "";
        if ($this->isProductDeprecation()) {
            $html = "";
        } elseif ($this->isWhmcsDeprecation()) {
            $html = "Deprecated since WHMCS " . $d["whmcs_version"] . ". ";
        }
        $html .= $d["notice"] . "<br/>";
        if (isset($d["url"])) {
            $html .= "Read more <a class=\"lnk-hx\" href=\"" . $d["url"] . "\" target=\"_blank\">here</a>.";
        }
        if (isset($d["replacementurl"])) {
            $html .= "<br/>Find a Replacement documented <a class=\"lnk-hx\" href=\"" . $d["replacementurl"] . "\" target=\"_blank\">here</a>.";
        }
        return $html;
    }

    public function getHTML()
    {
        // row color
        $notes = "";
        $cssClasses = [];
        $style = "";
        if ($this->isDeprecated()) {
            $cssClasses[] = "table-danger";
            $notes = "<span class=\"textred\">Deprecated</span>";
        } elseif ($this->isUsedVersionOutdated()) {
            $cssClasses[] = "table-warning";
        }
        if ($this->getStatus() === self::STATUS_NOTINSTALLED) {
            $style = "display: none;";
            $cssClasses[] = "mod-notinstalled";
        }
        $cssClasses = implode(" ", $cssClasses);

        // used version color
        $usedVersionColor = $this->isUsedVersionOutdated() ? "pink" : "green";

        $html = <<<HTML
            <tr class="{$cssClasses}" style="{$style}">
                <td>{$this->getName()}</td>
                <td>{$this->getStatus()}</td>
                <td>
                    <a class="btn btn-default btn-xs" href="{$this->getDocumentationLink()}" target="_blank" data-toggle="tooltip" data-placement="top" title="See documentation">
                        <i class="fas fa-book aria-hidden="true"></i>
                    </a>
        HTML;

        if ($this->isDeprecated()) {
            if (!$this->isStandardDeprecation()) {
                $html .= <<<HTML
                    <button class="btn btn-default btn-xs toggleDetailsView" data-elinfo="{$this->getWHMCSModuleId()}-details" data-toggle="tooltip" data-placement="top" title="Show Depracation Details">
                        <i class="fas fa-book-dead"></i>
                    </button>
                HTML;
            }
        } else {
            $html .= <<<HTML
                <a class="btn btn-default btn-xs" href="{$this->getDownloadLink()}" target="_blank" data-toggle="tooltip" data-placement="top" title="Download Module Package">
                    <i class="fas fa-download" aria-hidden="true"></i>
                </a>
            HTML;
        }

        $html .= <<<HTML
                </td>
                <td><span class="text small">{$this->getUsedVersion()}</span></td>
                <td><span class="color-{$usedVersionColor} small">{$this->getLatestVersion()}</span></td>
                <td>{$notes}</td>
            </tr>
        HTML;

        if ($this->isDeprecated() && !$this->isStandardDeprecation()) {
            $html .= <<<HTML
                <tr>
                    <td id="{$this->getWHMCSModuleId()}-details" class="bg-warning" colspan="6" style="display: none;">
                        {$this->getDeprecationNotice()}
                    </td>
                </tr>
                HTML;
        }
        return $html;
    }

    public function isStandardDeprecation()
    {
        $key = strtolower(self::STATUS_DEPRECATED);
        return (
            $this->isDeprecated()
            && $this->data[$key] === true
        );
    }

    public function isProductDeprecation()
    {
        $key = strtolower(self::STATUS_DEPRECATED);
        return (
            $this->isDeprecated()
            && isset($this->data[$key]["case"])
            && $this->data[$key]["case"] === "product"
        );
    }

    public function isWhmcsDeprecation()
    {
        $key = strtolower(self::STATUS_DEPRECATED);
        return (
            $this->isDeprecated()
            && isset($this->data[$key]["case"])
            && $this->data[$key]["case"] === "whmcs"
        );
    }

    /**
     * Check if Module is activated
     * @return bool
     */
    public function isActive()
    {
        return ($this->data["status"] === self::STATUS_ACTIVE);
    }

    /**
     * Check if Module is inactive
     * @return bool
     */
    public function isInactive()
    {
        return ($this->data["status"] === self::STATUS_INACTIVE);
    }

    /**
     * Check if Module is deprecated
     * @return bool
     */
    public function isDeprecated()
    {
        $key = strtolower(self::STATUS_DEPRECATED);
        return isset($this->data[$key]);
    }

    /**
     * Get WHMCS Module ID
     * @return string
     */
    public function getWHMCSModuleId()
    {
        return $this->whmcsid;
    }
    /**
     * Get Module Status
     * @return string
     */
    public function getStatus()
    {
        return self::STATUS_DEPRECATED;
    }

    /**
     * Get Module Type Identifier
     * @return string
     */
    public function getType()
    {
        return $this->data["type"];
    }

    /**
     * Get Module's Priority
     * @return int
     */
    public function getPriority()
    {
        return $this->data["prio"];
    }

    /**
     * Get Module's Used Version
     * @return string
     */
    public function getUsedVersion()
    {
        if ($this->isUsedVersionUnknown()) {
            return self::VERSION_UNKNOWN;
        }
        return $this->data["version_used"];
    }

    /**
     * Get Module's Latest Version
     * @return string
     */
    public function getLatestVersion()
    {
        return $this->data["version_latest"];
    }

    /**
     * Get Module's friendly Name
     * @return string
     */
    public function getName()
    {
        return $this->data["name"];
    }

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
            CURLOPT_URL => $this->getReleaseFileLink(),
            CURLOPT_HTTPHEADER => ["Cache-Control: no-cache, must-revalidate"]
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
    public function isLatestVersionUnknown()
    {
        return ($this->data["version_latest"] === self::VERSION_UNKNOWN);
    }

    /**
     * check if used version is unknown
     * @return bool
     */
    public function isUsedVersionUnknown()
    {
        return ($this->data["version_used"] === self::VERSION_UNKNOWN);
    }

    /**
     * check if used version is outdated
     * @return bool
     */
    public function isUsedVersionOutdated()
    {
        if (
            $this->isLatestVersionUnknown()
            || $this->isUsedVersionUnknown()
        ) {
            return false;
        }
        return version_compare(
            $this->data["version_used"],
            $this->data["version_latest"],
            '<'
        );
    }

    /**
     * get the uri to the raw version of the release.json file
     * @return string
     */
    private function getReleaseFileLink()
    {
        return $this->getGitHubLink(true) . "/master/release.json";
    }

    /**
     * get the uri to the module documentation
     * @return string
     */
    public function getDocumentationLink()
    {
        return "https://centralnic-reseller.github.io/centralnic-reseller/docs/hexonet/whmcs/{$this->data["repoid"]}";
    }

    /**
     * get the uri to the GitHub repository
     * @return string
     */
    public function getGitHubLink($raw = false)
    {
        $hostname = $raw ? "raw.githubusercontent.com" : "github.com";
        return "https://{$hostname}/hexonet/{$this->data["repoid"]}";
    }

    /**
     * get the uri to the latest version archive
     * @return string
     */
    public function getDownloadLink()
    {
        return ($this->getGitHubLink() . "/raw/master/{$this->data["repoid"]}-latest.zip");
    }
}

class IspapiRegistrar extends IspapiModule
{
    protected function loadData($rawData)
    {
        $repoid = $rawData["repoid"];
        if (isset($_SESSION[IspapiModulesWidget::$widgetid][$repoid])) { // data cache exists
            $this->data = $_SESSION[IspapiModulesWidget::$widgetid][$repoid];
            return;
        }

        parent::loadData($rawData);
        $v = constant(strtoupper($this->whmcsid) . "_MODULE_VERSION");
        if (!is_null($v)) {
            $this->data["version_used"] = $v;
        }

        $_SESSION[IspapiModulesWidget::$widgetid][$repoid] = $this->data;
    }
    /**
     * Get Module Status
     * @return string
     */
    public function getStatus()
    {
        $cl = "\\WHMCS\\Module\\" . ucfirst($this->data["type"]);
        $obj = new $cl();
        $isLoaded = $obj->load($this->whmcsid);
        if (!$isLoaded) {
            return self::STATUS_NOTINSTALLED;
        }
        return ($obj->isActivated()) ?
            self::STATUS_ACTIVE :
            self::STATUS_INACTIVE;
    }
}

class IspapiAddon extends IspapiModule
{
    protected function loadData($rawData)
    {
        $repoid = $rawData["repoid"];
        if (isset($_SESSION[IspapiModulesWidget::$widgetid][$repoid])) { // data cache exists
            $this->data = $_SESSION[IspapiModulesWidget::$widgetid][$repoid];
            return;
        }

        parent::loadData($rawData);
        $fn = $this->whmcsid . "_config";
        if (is_callable($fn)) {
            $tmp = call_user_func($fn);
            if (isset($tmp["version"])) {
                $this->data["version_used"] = $tmp["version"];
            }
        }

        $_SESSION[IspapiModulesWidget::$widgetid][$repoid] = $this->data;
    }
    /**
     * Get Status of the given $addon
     * @param string $module the whmcs module identifier e.g. ispapidpi
     * @param string $data module data (we need access to key deprecated)
     * @return string
     */
    public function getStatus()
    {
        global $CONFIG;
        static $activeaddons = null;
        if (is_null($activeaddons)) {
            $activeaddons = explode(",", $CONFIG["ActiveAddonModules"]);
        }

        $cl = "\\WHMCS\\Module\\" . ucfirst($this->data["type"]);
        $obj = new $cl();
        $isLoaded = $obj->load($this->whmcsid);  // necessary for version access

        if (in_array($this->whmcsid, $activeaddons)) {
            return self::STATUS_ACTIVE;
        }
        return ($isLoaded) ?
            self::STATUS_INACTIVE :
            self::STATUS_NOTINSTALLED;
    }
}

class IspapiWidget extends IspapiModule
{
    protected function loadData($rawData)
    {
        $repoid = $rawData["repoid"];
        if (isset($_SESSION[IspapiModulesWidget::$widgetid][$repoid])) { // data cache exists
            $this->data = $_SESSION[IspapiModulesWidget::$widgetid][$repoid];
            return;
        }

        parent::loadData($rawData);
        $tmp = explode("widget", $this->whmcsid);
        $cl = "\\WHMCS\\Module\\Widget\\" . ucfirst($tmp[0]) . ucfirst($tmp[1]) . "Widget";
        //NOTE: 2nd param in class_exists deactivates autoload!
        //otherwise we run into php warning for non-installed widgets
        if (class_exists($cl, false) && defined("$cl::VERSION")) {
            $this->data["version_used"] = $cl::VERSION;
        }

        $_SESSION[IspapiModulesWidget::$widgetid][$repoid] = $this->data;
    }
    /**
     * Get Module Status
     * @return string
     */
    public function getStatus()
    {
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

add_hook('AdminAreaHeadOutput', 1, function ($vars) {
    if ($vars["pagetitle"] === "Dashboard") {
        return <<<HTML
            <style>
            #panelIspapiModulesWidget a.lnk-hx {
                text-decoration: underline;
            }
            .hxmwTable {
                margin-top: 4px;
                margin-bottom: 10px;
            }
            .hxmwTable th:nth-of-type(1) {/*mod name*/
                width: 200px;
            }
            .hxmwTable th:nth-of-type(2) {/*mod status*/
                width: 100px;
            }
            .hxmwTable th:nth-of-type(3) {/*icons*/
                width: 70px;
                min-width: 70px;
            }
            .hxmwTable th:nth-of-type(4) {/* used */
                width: 110px;
            }
            .hxmwTable th:nth-of-type(5) { /* latest */
                width: 70px;
            }
            .hxmwTable th:nth-of-type(4), /* used */
            .hxmwTable td:nth-of-type(4),
            .hxmwTable th:nth-of-type(5), /* latest */
            .hxmwTable td:nth-of-type(5) {
                text-align: right;
            }
            .hxmwTable tr.table-danger > td {
                background-color: #fce4e4 !important;
            }
            .hxmwTable tr.table-warning > td {
                background-color: #fcf8e3 !important;
            }
            a.hx-widget-expires {
                text-decoration: none;
            }
            </style>
            <script>
            // toggle the details view
            function hxmwToggleDetailsHandler(event) {
                $("#"+$(this).data("elinfo")).toggle();
                $(this).children('.fa-caret-up, .fa-caret-down').toggleClass("fa-caret-up fa-caret-down");
                packery.shiftLayout();
            }
            function hxmwToggleFurtherModulesHandler(event) {
                $($(this).data("selector")).toggle();
                packery.shiftLayout();
            }
            function hxStartCounter(sel) {
                if (!$(sel).length) {
                    return;
                }
                setInterval(function(){
                    $(sel).each(hxDecrementCounter);
                }, 1000);
            }
            function hxDecrementCounter() {
                let expires = $(this).data("expires") - 1;
                const ttl = $(this).data("ttl");
                $(this).data("expires", expires);
                $(this).html(hxSecondsToHms(expires, ttl));
            }
            function hxSecondsToHms(d, ttl) {
                d = Number(d);
                const ttls = [3600,60,1];
                let units = ["h", "m", "s"];
                let vals = [
                    Math.floor(d / 3600), // h
                    Math.floor(d % 3600 / 60), // m
                    Math.floor(d % 3600 % 60) // s
                ];
                let steps = ttls.length;
                ttls.forEach(row => {
                    if (ttl / row === 1 && ttl % row === 0){
                        steps--;
                    }
                });
                vals = vals.splice(vals.length - steps);
                units = units.splice(units.length - steps);
                let html = "";
                vals.forEach((val, idx) => {
                    html += " " + val + units[idx];
                });
                return html.substr(1);
            }
            </script>
        HTML;
    }
});
