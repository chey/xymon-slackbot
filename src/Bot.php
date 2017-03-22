<?php
namespace XymonSlack;

use GuzzleHttp\Client as Guzzle;
use Xymon\Client\Board as XymonClient;
use Xymon\Message\Disable;
use Xymon\Message\Enable;
use Xymon\Message\Drop;

/**
 * @author chey
 */
class Bot extends \Slack\Bot
{
    /**
     * @var string
     */
    private $xymonUrl;

    /**
     * @var string
     */
    private $xymonWebUrl;

    /**
     * Constructor
     *
     * @throws \InvalidArgumentException
     */
    public function __construct()
    {
        $this->xymonUrl = getenv('XYMON_URL');

        if (!$this->xymonUrl) {
            throw new \InvalidArgumentException('Missing valid XYMON_URL');
        }

        $this->xymonWebUrl = getenv('XYMON_WEB_URL');

        if (!$this->xymonWebUrl) {
            throw new \InvalidArgumentException('Missing valid XYMON_WEB_URL');
        }

        $token = getenv('SLACK_TOKEN');

        if (!$token) {
            throw new \InvalidArgumentException('Missing valid SLACK_TOKEN');
        }

        parent::__construct($token);
    }

    /**
     * @param \Ratchet\RFC6455\Messaging\MessageInterface $msg
     */
    public function handleMessage(\Ratchet\RFC6455\Messaging\MessageInterface $msg)
    {
        $msgObj = json_decode($msg);
        if ($msgObj->type === 'message') {
            if ($this->isToMe($msgObj->text)) {
                $text = Utils::unfurl($msgObj->text);
                $command = strtolower(strtok($text, " \t\n"));
                if (trim($command) === '') {
                    $command = 'simplehelp';
                }
                $result = null;
                switch ($command) {
                    case 'enable':
                        $result = $this->enable($text, $msgObj);
                        break;
                    case 'disable':
                        $result = $this->disable($text, $msgObj);
                        break;
                    case 'drop':
                        $result = $this->drop(strtok(''), $msgObj);
                        break;
                    case 'nongreen':
                        $result = $this->nongreen($msgObj);
                        break;
                    case 'simplehelp':
                        $result = $this->simplehelp($msgObj);
                        break;
                    case 'help':
                        $result = $this->help($msgObj);
                        break;
                    default:
                        echo "User '{$msgObj->user}' running getHost() with '{$msgObj->text}'", PHP_EOL;
                        $result = $this->getHost($command, $msgObj);
                }
                // TODO: something with the $result
            }
        }
    }

    /**
     * @return bool
     */
    public function simpleMessage($text, \stdClass $msgObj)
    {
        $this->webSocket->send(json_encode([
            'id' => ++$this->msgID,
            'type' => 'message',
            'channel' => $msgObj->channel,
            'text' => $text,
            'mrkdwn' => true
        ]));
        return true;
    }

    /**
     * @return bool
     */
    public function help(\stdClass $msgObj)
    {
        $help = <<<EOF
Send me a host and I'll pull its status for you or send me one of the following commands:

*disable* HOSTNAME.TESTNAME DURATION <reason/cause>
>DURATION is in minutes. Append m/h/d to disable a test for minutes/hours/days respectively. Use -1 for DURATION to disable a test until it becomes OK. Use * as TESTNAME to disable all tests.
*enable* HOSTNAME.TESTNAME
>Use * as TESTNAME to enable all tests.
*drop* HOSTNAME [TESTNAME]
>Drop an entire host or a single test.
*nongreen*
>Show hosts currently in alarm. _(RED only)_
EOF;
        return $this->simpleMessage($help, $msgObj);
    }

    /**
     * @return bool
     */
    public function simplehelp(\stdClass $msgObj)
    {
        $me = $this->rtmdata->self->name;
        return $this->simpleMessage("I'm here. Send me a host and I'll pull its status for you. '@$me help' for more info.", $msgObj);
    }

    /**
     * @return bool
     */
    public function getHost($host, \stdClass $msgObj)
    {
        $host = filter_var($host, FILTER_SANITIZE_STRING);
        $result = $this->xymonClient()->select(compact('host'), ['testname', 'color', 'line1'])->fetchArray();
        $found = false;
        if (!empty($result)) {
            $found = true;
        }

        $message = $host;

        if (!$found) {
            $message = 'Sorry. I can\'t find ' . $host;
        } else {
            $message = 'Here is the current status for ' . $host;
        }

        $newmsg = [
            'token' => $this->slackToken,
            'channel' => $msgObj->channel,
            'text' => $message,
            'as_user' => true
        ];

        if ($found) {
            $attachments = [];
            foreach ($result as $test) {
                if ($test['testname'] !== 'info' && $test['testname'] !== 'trends' && $test['testname'] !== 'clientlog') {
                    $text = sprintf('*<%s|%s>* - %s', sprintf($this->xymonWebUrl, $host, $test['testname']), $test['testname'], strip_tags($test['line1']));
                    if ($test['color'] === 'blue') {
                        $text .= ' (DISABLED)';
                    }
                    $attachments[] = [
                        'fallback' => $text,
                        'color' => Utils::slackColor($test['color']),
                        'text' => $text,
                        'mrkdwn_in' => ['text', 'fallback']
                    ];
                }
            }

            if (!empty($attachments)) {
                $newmsg['attachments'] = json_encode($attachments);
            }
        }

        $response = $this->slackClient->request('POST', 'chat.postMessage', [
            'form_params' => $newmsg
        ]);

        return $response->getStatusCode() === 200;
    }

    /**
     * @param string $text
     * @param object $msgObj
     * @return bool
     */
    public function enable($text, \stdClass $msgObj)
    {
        $words = preg_split('/\s+/', $text, 2);
        list($hostname, $testname) = Utils::splitHost($words[1]);
        if (empty($testname)) {
            $testname = '*';
        }

        $messages = [];
        if ($testname === '*') {
            $tests = $this->xymonClient()->select(['host' => $hostname], ['testname', 'color'])->fetchArray();
            foreach ($tests as $test) {
                try {
                    $messages[] = new Enable(['hostname' => $hostname, 'testname' => $test['testname']]);
                } catch (\Exception $e) {}
            }
        } else {
            try {
                $messages[] = new Enable(compact('hostname', 'testname'));
            } catch (\Exception $e) {}
        }

        foreach ($messages as $message) {
            try {
                $response = $this->xymonClient()->execute($message);
            } catch (\Exception $e) {}
        }
        
        $this->simpleMessage("Enable message for $hostname sent successfully", $msgObj);

        return true;
    }

    /**
     * @param string $text
     * @param object $msgObj
     * @return bool
     */
    public function disable($text, \stdClass $msgObj)
    {
        $words = preg_split('/\s+/', $text, 4);

        list($hostname, $testname) = Utils::splitHost($words[1]);

        if (empty($testname)) {
            $testname = '*';
        }

        $duration = 0;
        $body = '(not given)';

        if (!empty($words[2])) {
            if (strtolower($words[2]) === 'ok') {
                $duration = '-1';
            } else {
                $duration = $words[2];
            }
        }

        if (!empty($words[3])) {
            $body = $words[3];
        }

        $body = "Disabled by: slackbot user\nReason: $body";

        try {
            $disable = new Disable(compact('hostname', 'testname', 'duration', 'body'));
            $response = $this->xymonClient()->execute($disable);
            if ($response->getStatusCode() === 200) {
                $this->simpleMessage("Disable message for $hostname sent successfully", $msgObj);
            }
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @param object $msgObj
     * @return bool
     */
    public function nongreen(\stdClass $msgObj)
    {
        $color = 'red';
        $lastchange = '>' . strtotime('-1 day');
        $acktime = '<=0';
        $tests = $this->xymonClient()->select(compact('color', 'lastchange', 'acktime'), ['hostname', 'testname', 'color', 'line1', 'XMH_NOPROPRED', 'XMH_PAGENAME', 'XMH_PAGEPATH'])->fetchArray();

        $newmsg = [
            'token' => $this->slackToken,
            'channel' => $msgObj->channel,
            'text' => 'Here are the systems currently in alarm (*RED*) as of the last 24hrs.',
            'as_user' => true
        ];

        $globalNoPropRed = explode(',', getenv('XYMON_SET_NOPROPRED'));

        // group by host, test and skip over the tests that aren't supposed to propagate
        $hosts = [];
        foreach ($tests as $test) {
            if (!in_array($test['testname'], $globalNoPropRed)) {
                $hosts[$test['hostname']][$test['testname']] = $test;
            }
        }
        unset($tests);

        // remove individuals with the nopropred set
        foreach ($hosts as $hostname => $tests) {
            foreach ($tests as $testname => $test) {
                $nopropred = explode(',', $test['XMH_NOPROPRED']);
                foreach ($nopropred as $n) {
                    $t = substr($n, 1);
                    if ($n{0} === '+' && isset($hosts[$hostname][$t])) {
                        unset($hosts[$hostname][$t]);
                    }
                }
            }
        }

        $xyHostUrl = getenv('XYMON_HOST_URL');

        // Build the attachments portion to send to slack
        $attachments = [];
        foreach ($hosts as $hostname => $tests) {
            if (!empty($tests)) {
                $first = current($tests);
                $text = sprintf('<%s|%s> - %s', sprintf($xyHostUrl, $first['XMH_PAGEPATH'], $first['XMH_PAGENAME']), $hostname, implode(', ', array_keys($tests)));
                $attachments[] = [
                    'fallback' => $text,
                    'color' => Utils::slackColor('red'),
                    'text' => $text,
                    'mrkdwn_in' => ['text', 'fallback']
                ];
            }
        }

        if (empty($attachments)) {
            unset($newmsg['text']);
            $text = 'All systems OK';
            $attachments[] = [
                'fallback' => $text,
                'color' => Utils::slackColor('green'),
                'text' => $text,
                'mrkdwn_in' => ['text', 'fallback']
            ];
        }

        $newmsg['attachments'] = json_encode($attachments);

        $response = $this->slackClient->request('POST', 'chat.postMessage', [
            'form_params' => $newmsg
        ]);

        return $response->getStatusCode() === 200;
    }

    public function drop($text, \stdClass $msgObj)
    {
        list($hostname, $testname) = preg_split('/\s+/', $text, 3);

        try {
            $response = $this->xymonClient()->execute(new Drop(compact('hostname', 'testname')));
            if ($response->getStatusCode() === 200) {
                $this->simpleMessage("Drop message for $hostname sent successfully", $msgObj);
            }
            return $response->getStatusCode() === 200;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function xymonClient()
    {
        return new XymonClient(['base_uri' => $this->xymonUrl, 'timeout' => 2]);
    }

    /**
     * @param string $text
     * @return bool
     */
    public function isToMe($text)
    {
        $myself = $this->rtmdata->self->id;
        return (bool) preg_match('/<@' . $myself . '>/', $text);
    }
}
