<?php


namespace Drakonia;


use ManiaControl\Callbacks\CallbackListener;
use ManiaControl\Callbacks\Callbacks;
use ManiaControl\Callbacks\Structures\TrackMania\OnScoresStructure;
use ManiaControl\Callbacks\Structures\TrackMania\OnWayPointEventStructure;
use ManiaControl\Callbacks\TimerListener;
use ManiaControl\Logger;
use ManiaControl\ManiaControl;
use ManiaControl\Plugins\Plugin;
use ManiaControl\Settings\Setting;
use ManiaControl\Settings\SettingManager;

class CasparCGPlugin implements CallbackListener, TimerListener, Plugin {

    /*
    * Constants
    */
    const PLUGIN_ID      = 999;
    const PLUGIN_VERSION = 0.1;
    const PLUGIN_NAME    = 'CasparCGPlugin';
    const PLUGIN_AUTHOR  = 'jonthekiller';


    const SETTING_CASPARCG_ACTIVATED = 'CasparCG-Plugin Activated';
    const SETTING_CASPARCG_ACTIONSFILE = 'CasparCG-Plugin Actions File';

    /** @var ManiaControl $maniaControl */
    private $maniaControl         = null;
    private $socket = false;
    private $connected = false;
    private $address;
    private $port;
    private $connector;
    private $firstfinish = true;
    private $sent = false;
    private $actionsfile = array();
    private $lastresults = array();
    private $matchpoints = 200;
    private $matchStarted = false;
    private $nbCheckpoints = 0;

    public function __construct() {
        $this->address = "127.0.0.1";
        $this->port = 5250;
        if (!$this->connectSocket()) {
            Logger::log("The socket could not be created.");
        }
    }

    /**
     * @see \ManiaControl\Plugins\Plugin::prepare()
     */
    public static function prepare(ManiaControl $maniaControl) {
    }

    /**
     * @see \ManiaControl\Plugins\Plugin::getId()
     */
    public static function getId() {
        return self::PLUGIN_ID;
    }

    /**
     * @see \ManiaControl\Plugins\Plugin::getName()
     */
    public static function getName() {
        return self::PLUGIN_NAME;
    }

    /**
     * @see \ManiaControl\Plugins\Plugin::getVersion()
     */
    public static function getVersion() {
        return self::PLUGIN_VERSION;
    }

    /**
     * @see \ManiaControl\Plugins\Plugin::getAuthor()
     */
    public static function getAuthor() {
        return self::PLUGIN_AUTHOR;
    }

    /**
     * @see \ManiaControl\Plugins\Plugin::getDescription()
     */
    public static function getDescription() {
        return 'Plugin for communication with CasparCG server';
    }

    /**
     * @see \ManiaControl\Plugins\Plugin::load()
     */
    public function load(ManiaControl $maniaControl) {
        $this->maniaControl = $maniaControl;

        // Settings
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_CASPARCG_ACTIVATED, true);
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_CASPARCG_ACTIONSFILE, '/home/drakonia/ManiaPlanet4/ManiaControl/CasparCG.txt');

        $this->connector = new CasparCGPlugin(); // all communication to the server will now be done through this object

        // Callbacks

        $this->maniaControl->getCallbackManager()->registerCallbackListener(SettingManager::CB_SETTING_CHANGED, $this, 'updateSettings');

        $this->maniaControl->getCallbackManager()->registerCallbackListener(Callbacks::MP_STARTROUNDSTART, $this, 'handleBeginRoundCallback');
        $this->maniaControl->getCallbackManager()->registerCallbackListener(Callbacks::TM_ONFINISHLINE, $this, 'handleFinishCallback');
        $this->maniaControl->getCallbackManager()->registerCallbackListener(Callbacks::BEGINMAP, $this, 'handleBeginMapCallback');
        $this->maniaControl->getCallbackManager()->registerCallbackListener(Callbacks::MP_WARMUP_START, $this, 'handleBeginWarmUpCallback');
        $this->maniaControl->getCallbackManager()->registerCallbackListener(Callbacks::TM_SCORES, $this, 'handleEndRoundCallback');
        $this->maniaControl->getCallbackManager()->registerCallbackListener(Callbacks::TM_ONWAYPOINT, $this, 'handleCheckpointCallback');


        $this->init();


        return true;

    }


    /**
     * @see \ManiaControl\Plugins\Plugin::unload()
     */
    public function unload() {
    }

    public function init()
    {
        $file = $this->maniaControl->getSettingManager()->getSettingValue($this, self::SETTING_CASPARCG_ACTIONSFILE);
        if (file_exists($file)) {
            $lines = explode(PHP_EOL, file_get_contents($file));
            foreach($lines as $line) {
                list($key, $value) = explode('=', $line, 2);
                $this->actionsfile[$key] = $value;
            }
            if(count($this->actionsfile) > 1)
                $this->maniaControl->getChat()->sendSuccessToAdmins("CASPARCG File successfully loaded: " . $file);
            else
                $this->maniaControl->getChat()->sendErrorToAdmins("Error while trying reading the CASPARCG File: " . $file);
        }else{
            $this->maniaControl->getChat()->sendErrorToAdmins("CASPARCG File doesn't exist: " . $file);
            $this->actionsfile = array();
        }

        //var_dump($this->actionsfile);
    }

    public function sendActiontoCASPARCG($actionname,$variable = null)
    {
        if ($action = $this->actionsfile[$actionname])
        {
            if($actionname == "Checkpoint")
            {
                $variables = explode($variable, "|");
                //$variables[0] = login
                //$variables[1] = percentage
                $action = str_replace('$percentage_checkpoint$', $variables[1], $action);
            }
            if($actionname == "BeginMap")
            {
                $action = str_replace('$NOM_DE_LA_MAP$', $variable, $action);
            }


            Logger::log('Sent ' . $action . ' to CASPARCG Server');
            $response = $this->connector->makeRequest($action);
        }else{
            Logger::log('Action missing ' . $action . ' to CASPARCG Server');
        }
    }


    public function handleBeginMapCallback()
    {
        $map = $this->maniaControl->getMapManager()->getCurrentMap();
        if($this->maniaControl->getClient()->getModeScriptInfo()->name == "Cup.Script.txt")
        {
            $this->matchStarted = true;
            $this->sendActiontoCASPARCG("BeginMap", $map->name);
        }else{
            $this->matchStarted = false;
        }
        $this->nbCheckpoints = $map->nbCheckpoints;
    }

    public function handleBeginRoundCallback()
    {
        if ($this->matchStarted) {
            $this->firstfinish = true;
            $this->sent = true;
            $this->sendActiontoCASPARCG("FeuRouge");
            $this->maniaControl->getTimerManager()->registerOneTimeListening($this, function () use (&$player) {
                $this->sendActiontoCASPARCG("FeuOrange");
            }, 1000);
            $this->maniaControl->getTimerManager()->registerOneTimeListening($this, function () use (&$player) {
                $this->sendActiontoCASPARCG("FeuVert");
            }, 1000);
        }
    }

    public function handleCheckpointCallback(OnWayPointEventStructure $structure)
    {
        if ($this->matchStarted) {

            $currentCheckpoint = ($structure->getCheckPointInRace() + 1);
            $percentage = ($currentCheckpoint / $this->nbCheckpoints);
            $login = $structure->getLogin();
            $this->sendActiontoCASPARCG("Checkpoint", $login . "|".$percentage);
        }
    }

    public function handleFinishCallback(OnWayPointEventStructure $structure)
    {
        if ($this->matchStarted) {
            $finalist = false;
            $winner = false;
            if ($this->firstfinish) {
                // Send packet only for the 1st finish
                $this->firstfinish = false;


                $player = $structure->getPlayer();

                if ($this->lastresults[$player->login] == "Finalist") {
                    $winner = true;
                }

                if ($this->sent) {
                    if ($action = $this->actionsfile['FinishRound'] && !$winner) {
                        $this->sendActiontoCASPARCG("FinishRound");
                    } elseif ($finalist && !$winner) {
                        //$this->sendActiontoCASPARCG("Finalist");
                        //$this->sent = false;
                    } elseif ($winner) {
                        $this->sendActiontoCASPARCG("Winner");
                        //$this->sent = false;
                    }
                }
            }
        }
    }

    public function handleBeginWarmUpCallback(){

    }

    public function handleEndRoundCallback(OnScoresStructure $structure)
    {
        if($structure->getSection() == "EndRound" AND $this->matchStarted) {
            $results = $structure->getPlayerScores();
            //$this->lastresults = array();
            foreach ($results as $result) {
                if ($result->getPlayer()->isSpectator) {

                } else {
                    $login = $result->getPlayer()->login;
                    $points = $result->getMatchPoints();


                    if ($this->matchpoints == $points) {
                        $this->lastresults[$login] = "Finalist";
                        $this->sendActiontoCASPARCG("Finalist");
                    } elseif ($this->matchpoints < $points) {
                        $this->lastresults[$login] = "Winner";
                    } else {
                        $this->lastresults[$login] = $points;
                    }

                }
            }
        }
    }



    private function connectSocket()
    {
        if ($this->connected) {
            return TRUE;
        }
        $this->socket = fsockopen($this->address, $this->port, $errno, $errstr, 10);
        if ($this->socket !== FALSE) {
            $this->connected = TRUE;
        }
        return $this->connected;
    }


    public function makeRequest($out) {
        if (!$this->connectSocket()) { // reconnect if not connected
            return FALSE;
        }
        fwrite($this->socket, $out . "\r\n");

        $line = fgets($this->socket);
        $line = explode(" ", $line);
        $status = intval($line[0], 10);
        $hasResponse = true;
        if ($status ===  200) { // several lines followed by empty line
            $endSequence = "\r\n\r\n";
        }
        else if ($status === 201) { // one line of data returned
            $endSequence = "\r\n";
        }
        else {
            $hasResponse = FALSE;
        }

        if ($hasResponse) {
            $response = stream_get_line($this->socket, 1000000, $endSequence);
        }
        else {
            $response = FALSE;
        }
        return array("status"=>$status, "response"=>$response);
    }

    public static function escapeString($string) {
        return str_replace('"', '\"', str_replace("\n", '\n', str_replace('\\', '\\\\', $string)));
    }

    public function closeSocket() {
        if (!$this->connected) {
            return TRUE;
        }
        fclose($this->socket);
        $this->connected = FALSE;
        return TRUE;
    }

    public function __destruct() {
        $this->closeSocket();
    }

    /**
     * Update Widgets on Setting Changes
     *
     * @param Setting $setting
     */
    public function updateSettings(Setting $setting) {
        if ($setting->belongsToClass($this)) {
            $this->init();
        }
    }
}