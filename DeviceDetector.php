<?php
/**
 * Device Detector - The Universal Device Detection library for parsing User Agents
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace DeviceDetector;

use DeviceDetector\Cache\CacheInterface;
use DeviceDetector\Cache\CacheStatic;
use DeviceDetector\Parser\Bot;
use DeviceDetector\Parser\Client\ClientParserAbstract;
use DeviceDetector\Parser\Device\DeviceParserAbstract;
use DeviceDetector\Parser\Device\HbbTv;
use DeviceDetector\Parser\Device\Mobile;
use DeviceDetector\Parser\OperatingSystem;
use \Spyc;

class DeviceDetector
{
    /**
     * Detectable device types
     * @var array
     */
    public static $deviceTypes = array(
        'desktop',          // 0
        'smartphone',       // 1
        'tablet',           // 2
        'feature phone',    // 3
        'console',          // 4
        'tv',               // 5
        'car browser',      // 6
        'smart display',    // 7
        'camera'            // 8
    );

    /**
     * Holds all registered client types
     * @var array
     */
    public static $clientTypes = array();

    /**
     * Operating system families that are known as desktop only
     *
     * @var array
     */
    protected static $desktopOsArray = array('AmigaOS', 'IBM', 'Linux', 'Mac', 'Unix', 'Windows', 'BeOS');

    /**
     * Constant used as value for unknown browser / os
     */
    const UNKNOWN = "UNK";

    /**
     * Holds the useragent that should be parsed
     * @var string
     */
    protected $userAgent;

    /**
     * Holds the operating system data after parsing the UA
     * @var array
     */
    protected $os = null;

    /**
     * Holds the client data after parsing the UA
     * @var array
     */
    protected $client = null;

    /**
     * Holds the device type after parsing the UA
     * @var string
     */
    protected $device = '';

    /**
     * Holds the device brand data after parsing the UA
     * @var string
     */
    protected $brand = '';

    /**
     * Holds the device model data after parsing the UA
     * @var string
     */
    protected $model = '';

    /**
     * Holds bot information if parsing the UA results in a bot
     * (All other information attributes will stay empty in that case)
     *
     * If $discardBotInformation is set to true, this property will be set to
     * true if parsed UA is identified as bot, additional information will be not available
     *
     * @var array|boolean
     */
    protected $bot = null;

    protected $discardBotInformation = false;

    /**
     * Holds the cache class used for caching the parsed yml-Files
     * @var CacheInterface
     */
    protected $cache = null;

    /**
     * Constructor
     *
     * @param string $userAgent  UA to parse
     */
    public function __construct($userAgent)
    {
        $this->userAgent = $userAgent;

        $this->addClientParser('FeedReader');
        $this->addClientParser('MobileApp');
        $this->addClientParser('MediaPlayer');
        $this->addClientParser('PIM');
        $this->addClientParser('Browser');

        $this->addDeviceParser('HbbTv');
        $this->addDeviceParser('Console');
        $this->addDeviceParser('Mobile');
    }

    /**
     * @var ClientParserAbstract[]
     */
    protected $clientParsers = array();

    /**
     * @param ClientParserAbstract|string $parser
     * @throws \Exception
     */
    public function addClientParser($parser)
    {
        if (is_string($parser) && class_exists('DeviceDetector\\Parser\\Client\\'.$parser)) {
            $className = 'DeviceDetector\\Parser\\Client\\'.$parser;
            $parser = new $className();
        }

        if ($parser instanceof ClientParserAbstract) {
            $this->clientParsers[] = $parser;
            self::$clientTypes[] = $parser->getName();
            return;
        }

        throw new \Exception('client parser not found');
    }

    public function getClientParsers()
    {
        return $this->clientParsers;
    }

    /**
     * @var DeviceParserAbstract[]
     */
    protected $deviceParsers = array();

    /**
     * @param DeviceParserAbstract|string $parser
     * @throws \Exception
     */
    public function addDeviceParser($parser)
    {
        if (is_string($parser) && class_exists('DeviceDetector\\Parser\\Device\\'.$parser)) {
            $className = 'DeviceDetector\\Parser\\Device\\'.$parser;
            $parser = new $className();
        }

        if ($parser instanceof DeviceParserAbstract) {
            $this->deviceParsers[] = $parser;
            return;
        }

        throw new \Exception('device parser not found');
    }

    public function getDeviceParsers()
    {
        return $this->deviceParsers;
    }

    /**
     * Sets whether to discard additional bot information
     * If information is discarded it's only possible check whether UA was detected as bot or not.
     * (Discarding information speeds up the detection a bit)
     *
     * @param bool $discard
     */
    public function discardBotInformation($discard=true)
    {
        $this->discardBotInformation = $discard;
    }

    /**
     * Returns if the parsed UA was identified as a Bot
     *
     * @see bots.yml for a list of detected bots
     *
     * @return bool
     */
    public function isBot()
    {
        return !empty($this->bot);
    }

    /**
     * Returns if the parsed UA was identified as a touch enabled device
     *
     * Note: That only applies to windows 8 tablets
     *
     * @return bool
     */
    public function isTouchEnabled()
    {
        $regex = 'Touch';
        return $this->matchUserAgent($regex);
    }

    public function isMobile()
    {
        return !$this->isDesktop();
    }

    /**
     * Returns if the parsed UA was identified as desktop device
     * Desktop devices are all devices with an unknown type that are running a desktop os
     *
     * @see self::$desktopOsArray
     *
     * @return bool
     */
    public function isDesktop()
    {
        $osShort = $this->getOs('short_name');
        if (empty($osShort)) {
            return false;
        }

        $decodedFamily = OperatingSystem::getOsFamily($osShort);

        return in_array($decodedFamily, self::$desktopOsArray);
    }

    /**
     * Returns the operating system data extracted from the parsed UA
     *
     * If $attr is given only that property will be returned
     *
     * @param string $attr  property to return(optional)
     *
     * @return array|string
     */
    public function getOs($attr = '')
    {
        if ($attr == '') {
            return $this->os;
        }

        if (!isset($this->os[$attr])) {
            return self::UNKNOWN;
        }

        return $this->os[$attr];
    }

    /**
     * Returns the client data extracted from the parsed UA
     *
     * If $attr is given only that property will be returned
     *
     * @param string $attr  property to return(optional)
     *
     * @return array|string
     */
    public function getClient($attr = '')
    {
        if ($attr == '') {
            return $this->client;
        }

        if (!isset($this->client[$attr])) {
            return self::UNKNOWN;
        }

        return $this->client[$attr];
    }

    /**
     * Returns the device type extracted from the parsed UA
     *
     * @see self::$deviceTypes for available device types
     *
     * @return string
     */
    public function getDevice()
    {
        return $this->device;
    }

    /**
     * Returns the device brand extracted from the parsed UA
     *
     * @see self::$deviceBrand for available device brands
     *
     * @return string
     */
    public function getBrand()
    {
        return $this->brand;
    }

    /**
     * Returns the device model extracted from the parsed UA
     *
     * @return string
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * Returns the user agent that is set to be parsed
     *
     * @return string
     */
    public function getUserAgent()
    {
        return $this->userAgent;
    }

    /**
     * Returns the bot extracted from the parsed UA
     *
     * @return array
     */
    public function getBot()
    {
        return $this->bot;
    }

    /**
     * Triggers the parsing of the current user agent
     */
    public function parse()
    {
        $this->parseBot();
        if ($this->isBot())
            return;

        $this->parseOs();

        /**
         * Parse Clients
         * Clients might be browsers, Feed Readers, Mobile Apps, Media Players or
         * any other application accessing with an parseable UA
         */
        $this->parseClient();

        $this->parseDevice();
    }

    /**
     * Parses the UA for bot information using the Bot parser
     */
    protected function parseBot()
    {
        $botParser = new Bot();
        $botParser->setUserAgent($this->getUserAgent());
        if ($this->discardBotInformation) {
            $botParser->discardDetails();
        }
        $this->bot = $botParser->parse();
    }


    protected function parseClient() {

        $parsers = $this->getClientParsers();

        foreach ($parsers AS $parser) {
            $parser->setCache($this->getCache());
            $parser->setUserAgent($this->getUserAgent());
            $client = $parser->parse();
            if (!empty($client)) {
                $this->client = $client;
                break;
            }
        }
    }

    protected function parseDevice() {

        $parsers = $this->getDeviceParsers();

        foreach ($parsers AS $parser) {
            $parser->setCache($this->getCache());
            $parser->setUserAgent($this->getUserAgent());
            if ($parser->parse()) {
                $this->device = array_search($parser->getDeviceType(), self::$deviceTypes);
                $this->model  = $parser->getModel();
                $this->brand  = $parser->getBrand();
                break;
            }
        }

        // set device type to desktop for all devices running a desktop os
        if (empty($this->device) && $this->isDesktop()) {
            $this->device = array_search('desktop', self::$deviceTypes);
        }

        /**
         * Android up to 3.0 was designed for smartphones only. But as 3.0, which was tablet only, was published
         * too late, there were a bunch of tablets running with 2.x
         * With 4.0 the two trees were merged and it is for smartphones and tablets
         *
         * So were are expecting that all devices running Android < 2 are smartphones
         * Devices running Android 3.X are tablets. Device type of Android 2.X and 4.X+ are unknown
         */
        if (empty($this->device) && $this->getOs('short_name') == 'AND' && $this->getOs('version') != '') {
            if (version_compare($this->getOs('version'), '2.0') == -1) {
                $this->device = array_search('smartphone', self::$deviceTypes);
            } else if (version_compare($this->getOs('version'), '3.0') >= 0 AND version_compare($this->getOs('version'), '4.0') == -1) {
                $this->device = array_search('tablet', self::$deviceTypes);
            }
        }

        /**
         * According to http://msdn.microsoft.com/en-us/library/ie/hh920767(v=vs.85).aspx
         * Internet Explorer 10 introduces the "Touch" UA string token. If this token is present at the end of the
         * UA string, the computer has touch capability, and is running Windows 8 (or later).
         * This UA string will be transmitted on a touch-enabled system running Windows 8 (RT)
         *
         * As most touch enabled devices are tablets and only a smaller part are desktops/notebooks we assume that
         * all Windows 8 touch devices are tablets.
         */
        if (empty($this->device) && in_array($this->getOs('short_name'), array('WI8', 'WRT')) && $this->isTouchEnabled()) {
            $this->device = array_search('tablet', self::$deviceTypes);
        }
    }

    protected function parseOs()
    {
        $osParser = new OperatingSystem();
        $osParser->setUserAgent($this->getUserAgent());
        $osParser->setCache($this->getCache());
        $this->os = $osParser->parse();
    }

    protected function matchUserAgent($regex)
    {
        $regex = '/(?:^|[^A-Z_-])(?:' . str_replace('/', '\/', $regex) . ')/i';

        if (preg_match($regex, $this->userAgent, $matches)) {
            return $matches;
        }

        return false;
    }

    static public function getInfoFromUserAgent($ua)
    {
        $deviceDetector = new DeviceDetector($ua);
        $deviceDetector->parse();

        $osFamily = OperatingSystem::getOsFamily($deviceDetector->getOs('short_name'));
        $browserFamily = \DeviceDetector\Parser\Client\Browser::getBrowserFamily($deviceDetector->getClient('short_name'));
        $device = $deviceDetector->getDevice();

        $deviceName = $device === '' ? '' : DeviceDetector::$deviceTypes[$device];
        $processed = array(
            'user_agent'     => $deviceDetector->getUserAgent(),
            'os'             => array(
                'name'       => $deviceDetector->getOs('name'),
                'short_name' => $deviceDetector->getOs('short_name'),
                'version'    => $deviceDetector->getOs('version'),
            ),
            'client'        => array(
                'type'       => $deviceDetector->getClient('type'),
                'name'       => $deviceDetector->getClient('name'),
                'short_name' => $deviceDetector->getClient('short_name'),
                'version'    => $deviceDetector->getClient('version'),
            ),
            'device'         => array(
                'type'       => $deviceName,
                'brand'      => $deviceDetector->getBrand(),
                'model'      => $deviceDetector->getModel(),
            ),
            'os_family'      => $osFamily !== false ? $osFamily : 'Unknown',
            'browser_family' => $browserFamily !== false ? $browserFamily : 'Unknown',
        );
        return $processed;
    }

    /**
     * Sets the Cache class
     *
     * Note: The given class needs to have a 'get' and 'set' method to be used
     *
     * @param $cache
     */
    public function setCache(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    /**
     * Returns Cache object
     *
     * @return CacheInterface
     */
    public function getCache()
    {
        if (!empty($this->cache)) {
            return $this->cache;
        }

        return new CacheStatic();
    }
}