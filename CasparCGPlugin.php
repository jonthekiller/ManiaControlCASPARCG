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
    private $matchpoints = 150;
    private $matchStarted = false;
    private $nbCheckpoints = 0;

    public function __construct() {
        $this->address = "192.168.1.164";
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
        $this->maniaControl->getSettingManager()->initSetting($this, self::SETTING_CASPARCG_ACTIONSFILE, '/home/drakonia/ManiaControl/CasparCG.txt');

        $this->connector = new CasparCGPlugin(); // all communication to the server will now be done through this object

        // Callbacks

        $this->maniaControl->getCallbackManager()->registerCallbackListener(SettingManager::CB_SETTING_CHANGED, $this, 'updateSettings');

        $this->maniaControl->getCallbackManager()->registerCallbackListener(Callbacks::MP_STARTROUNDSTART, $this, 'handleBeginRoundCallback');
        $this->maniaControl->getCallbackManager()->registerCallbackListener(Callbacks::TM_ONFINISHLINE, $this, 'handleCheckpointCallback');
        $this->maniaControl->getCallbackManager()->registerCallbackListener(Callbacks::TM_ONLAPFINISH, $this, 'handleCheckpointCallback');
        $this->maniaControl->getCallbackManager()->registerCallbackListener(Callbacks::BEGINMAP, $this, 'handleBeginMapCallback');
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
//Logger::log("Action: ".$key);
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
        if($actionname) {
            if ($action = $this->actionsfile[$actionname]) {
                if (strpos($actionname, 'Checkpoint') !== false) {
                    $action = str_replace('$PERCENT$', $variable, $action);
                }
                if (strpos($actionname, 'CPCar') !== false) {
                    $action = str_replace('$END$', $variable, $action);
                }
                if (strpos($actionname, 'Score') !== false) {
                    $action = str_replace('$SCORE$', $variable, $action);
                }
                if ($actionname == "BeginMap") {
                    $action = str_replace('$NOM_DE_LA_MAP$', $variable, $action);
                }


                Logger::log($action);
                $response = $this->connector->makeRequest($action);
            } else {
                Logger::log('Action missing ' . $action . ' to CASPARCG Server');
            }
        }
    }


    public function handleBeginMapCallback()
    {
        $map = $this->maniaControl->getMapManager()->getCurrentMap();
        if($this->maniaControl->getClient()->getModeScriptInfo()->name == "Cup.Script.txt")
        {
            $this->matchStarted = true;
            //$this->sendActiontoCASPARCG("BeginMap", $map->uid);
            $this->sendActiontoCASPARCG("Playernick1");
            $this->sendActiontoCASPARCG("Playernick2");
            $this->sendActiontoCASPARCG("Playernick3");
            $this->sendActiontoCASPARCG("Playernick4");
            $this->sendActiontoCASPARCG("Checkpoint01");
            $this->sendActiontoCASPARCG("Checkpoint02");
            $this->sendActiontoCASPARCG("Checkpoint03");
            $this->sendActiontoCASPARCG("Checkpoint04");
            $this->sendActiontoCASPARCG("Background");
            $this->sendActiontoCASPARCG("WarmUp1");
        }else{
            $this->matchStarted = false;
        }

        if($map->uid == "EkNh3L4HhX7MTdxp6sb435OLdJ")
        {
            $this->nbCheckpoints = $map->nbCheckpoints * 2;
        }else {
            $this->nbCheckpoints = $map->nbCheckpoints;
        }
    }

    public function handleBeginRoundCallback()
    {
        if ($this->matchStarted) {
            $this->firstfinish = true;
            $this->sent = true;
            
            $this->sendActiontoCASPARCG("WarmUp2");
            $this->sendActiontoCASPARCG("Checkpoint1", 0);
            $this->sendActiontoCASPARCG("Checkpoint2", 0);
            $this->sendActiontoCASPARCG("Checkpoint3", 0);
            $this->sendActiontoCASPARCG("Checkpoint4", 0);
            $this->sendActiontoCASPARCG("ClearCP1");
            $this->sendActiontoCASPARCG("ClearCP2");
            $this->sendActiontoCASPARCG("ClearCP3");
            $this->sendActiontoCASPARCG("ClearCP4");
            $this->sendActiontoCASPARCG("FeuRouge1");
            $this->sendActiontoCASPARCG("FeuRouge2");
            $this->sendActiontoCASPARCG("FeuOrange1");
            $this->sendActiontoCASPARCG("FeuOrange2");
            $this->sendActiontoCASPARCG("FeuVert1");
            $this->sendActiontoCASPARCG("FeuVert2");

            $this->maniaControl->getTimerManager()->registerOneTimeListening($this, function () use (&$player) {
                $this->sendActiontoCASPARCG("Start1");
                
            }, 2800);

            $this->maniaControl->getTimerManager()->registerOneTimeListening($this, function () use (&$player) {
                $this->sendActiontoCASPARCG("FeuRouge3");
                $this->sendActiontoCASPARCG("FeuRouge4");
                $this->sendActiontoCASPARCG("FeuOrange3");
                $this->sendActiontoCASPARCG("FeuOrange4");
                $this->sendActiontoCASPARCG("FeuVert3");
                $this->sendActiontoCASPARCG("FeuVert4");
            }, 4000);

            $this->maniaControl->getTimerManager()->registerOneTimeListening($this, function () use (&$player) {
                $this->sendActiontoCASPARCG("Start2");
            }, 7500);
        }
    }

    public function handleCheckpointCallback(OnWayPointEventStructure $structure)
    {
        if ($this->matchStarted) {

            $length = round(1800/($this->nbCheckpoints),0);
            
            $currentCheckpoint = ($structure->getCheckPointInRace() + 1);
            $percentage = round(($currentCheckpoint / $this->nbCheckpoints)*100,1);
            $percentage2 = round((($currentCheckpoint -1) / $this->nbCheckpoints)*100,1);
            $login = $structure->getLogin();
            $action = "";
            switch (trim($login)) {
                case trim($this->actionsfile['Player1']):
                    $action = "Checkpoint1";
                    $action2 = "CPCar1";
                    break;
                case trim($this->actionsfile['Player2']):
                    $action = "Checkpoint2";
                    $action2 = "CPCar2";
                    break;
                case trim($this->actionsfile['Player3']):
                    $action = "Checkpoint3";
                    $action2 = "CPCar3";
                    break;
                case trim($this->actionsfile['Player4']):
                    $action = "Checkpoint4";
                    $action2 = "CPCar4";
                    break;
            }

            $endlength = "LENGTH ".$length . " SEEK ".round(($percentage2*18),0) ." ";
            //Logger::log("Action: " . $action);
            $this->sendActiontoCASPARCG($action, $percentage);
            $this->sendActiontoCASPARCG($action2, $endlength);
            
            
        }
    }

//
//    public function handleFinishCallback(OnWayPointEventStructure $structure)
//    {
//        if ($this->matchStarted) {
//            $finalist = false;
//            $winner = false;
//            if ($this->firstfinish) {
//                // Send packet only for the 1st finish
//                $this->firstfinish = false;
//
//
//                $player = $structure->getPlayer();
//
//                if ($this->lastresults[$player->login] == "Finalist") {
//                    $winner = true;
//                }
//
//                if ($this->sent) {
//                    if ($action = $this->actionsfile['FinishRound'] && !$winner) {
//                        $this->sendActiontoCASPARCG("FinishRound");
//                    } elseif ($finalist && !$winner) {
//                        switch ($player->login){
//                            case $this->actionsfile['Player1']:
//                                $action = "Checkpoint1";
//                                break;
//                            case $this->actionsfile['Player2']:
//                                $action = "Checkpoint2";
//                                break;
//                            case $this->actionsfile['Player3']:
//                                $action = "Checkpoint3";
//                                break;
//                            case $this->actionsfile['Player4']:
//                                $action = "Checkpoint4";
//                                break;
//                        }
//                        //$this->sendActiontoCASPARCG("Finalist");
//                        //$this->sent = false;
//                    } elseif ($winner) {
//                        $this->sendActiontoCASPARCG("Winner");
//                        //$this->sent = false;
//                    }
//                }
//            }
//        }
//    }


    public function handleEndRoundCallback(OnScoresStructure $structure)
    {
        if($structure->getSection() == "PreEndRound" AND $this->matchStarted) {
            $results = $structure->getPlayerScores();
            //$this->lastresults = array();
            foreach ($results as $result) {
                if ($result->getPlayer()->isSpectator) {

                } else {
                    $login = $result->getPlayer()->login;
                    $points = $result->getMatchPoints() + $result->getRoundPoints();

                    if($points == "")
                    {
                        $points = 0;
                    }

                    $points2 = false;
                    $winner = false;
                    if (isset($this->lastresults[$login])) {
                        if ($this->lastresults[$login] == "Finalist") {
                            $points2 = true;
                        } elseif ($this->lastresults[$login] == "Winner") {
                            $points2 = false;
                            $winner = true;
                        } elseif ($points >= $this->matchpoints) {
                            $points = $this->matchpoints;
                            $points2 = true;
                        }
                    }elseif ($points >= $this->matchpoints) {
                        $points = $this->matchpoints;
                        $points2 = true;
                    }

                    if($points < 10)
                        $points = "0".$points;

                    $action = "";
                    switch (trim($login)){
                        case trim($this->actionsfile['Player1']):
                            $action = "Score1";
                            break;
                        case trim($this->actionsfile['Player2']):
                            $action = "Score2";
                            break;
                        case trim($this->actionsfile['Player3']):
                            $action = "Score3";
                            break;
                        case trim($this->actionsfile['Player4']):
                            $action = "Score4";
                            break;
                    }

                    if($points2) {
                        if ($this->matchpoints < $points AND $this->lastresults[$login] == "Finalist") {

                            $this->lastresults[$login] = "Winner";
                            switch (trim($login)) {
                                case trim($this->actionsfile['Player1']):
                                    $action = "Winner1";
                                    break;
                                case trim($this->actionsfile['Player2']):
                                    $action = "Winner2";
                                    break;
                                case trim($this->actionsfile['Player3']):
                                    $action = "Winner3";
                                    break;
                                case trim($this->actionsfile['Player4']):
                                    $action = "Winner4";
                                    break;
                            }
                            $this->sendActiontoCASPARCG($action);

                        } elseif ($this->matchpoints <= $points AND $this->lastresults[$login] != "Winner") {
                            $this->lastresults[$login] = "Finalist";
                            switch (trim($login)) {
                                case trim($this->actionsfile['Player1']):
                                    $action1 = "Finalist1";
                                    $action2 = "Score1";
                                    break;
                                case trim($this->actionsfile['Player2']):
                                    $action1 = "Finalist2";
                                    $action2 = "Score2";
                                    break;
                                case trim($this->actionsfile['Player3']):
                                    $action1 = "Finalist3";
                                    $action2 = "Score3";
                                    break;
                                case trim($this->actionsfile['Player4']):
                                    $action1 = "Finalist4";
                                    $action2 = "Score4";
                                    break;
                            }
                            $this->sendActiontoCASPARCG($action1);
                            $this->sendActiontoCASPARCG($action2, $points);
                        } else {
                            $this->sendActiontoCASPARCG($action, $points);
                            $this->lastresults[$login] = $points;
                        }
                    }else {

                        if(!$winner) {
                            $this->lastresults[$login] = $points;
                            $this->sendActiontoCASPARCG($action, $points);
                        }
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