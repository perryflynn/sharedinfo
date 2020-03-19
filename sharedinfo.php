<?php

$startTime = microtime(true);

/**
 * Throw exceptions on php errors
 */
set_error_handler(function($errno, $errstr, $errfile, $errline, $errcontext)
{
    // error was suppressed with the @-operator
    if (0 === error_reporting())
    {
        return false;
    }

    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

/**
 * Try multiple operations until one is successful
 */
function tryThings(array $things)
{
    foreach($things as $thing)
    {
        try
        {
            return $thing();
        }
        catch(ErrorException $ex)
        { }
    }

    return null;
}

/**
 * Try multiple ways to execute shell commands and return output
 */
function smartExec($cmd)
{
    return tryThings(array(
        function() use($cmd)
        {
            return shell_exec($cmd);
        },
        function() use($cmd)
        {
            $lines = array();
            exec($cmd, $lines);
            return implode("\n", $lines);
        },
        function() use($cmd)
        {
            ob_start();
            ob_clean();
            passthru($cmd);
            $data = ob_get_contents();
            ob_end_clean();
            return $data;
        },
        function() use($cmd)
        {
            return `{$cmd}`;
        }
    ));
}

/**
 * Try multiple ways to get the content of a file
 */
function smartFile($file)
{
    return tryThings(array(
        function() use($file) { return file_get_contents($file); },
        function() use($file) { return smartExec("cat ".escapeshellarg($file)); }
    ));
}

/**
 * Just do a one-line-regex-match
 */
function smartMatch($string, $regex, $group = 1)
{
    $match = array();
    if (preg_match($regex, $string, $match) === 1)
    {
        return $match[$group];
    }
    return null;
}

/**
 * String to integer or float
 */
function smartNumber($str)
{
    if (is_null($str))
    {
        return null;
    }

    if(strpos($str, '.') === false)
    {
        return (int)$str;
    }

    return (float)$str;
}

/**
 * Enforce a list of fields in the given input array
 */
function ensureArray($input, array $fields)
{
    $result = array();
    foreach($fields as $field)
    {
        if(is_array($input) && array_key_exists($field, $input))
        {
            $result[$field] = $input[$field];
        }
        else
        {
            $result[$field] = null;
        }
    }

    return $result;
}

/**
 * Collect data
 */

// CPU Info
$cpuInfo = smartFile("/proc/cpuinfo");

$cpuVendor = null;
$cpuName = null;
$cpuMicrocodeVersion = null;
$cpuBugs = null;
$cpuCount = null;
$cpuMHz = null;
$cpuFlags = null;

if (is_string($cpuInfo))
{
    $cpuVendor = smartMatch($cpuInfo, '/^vendor_id\s+:\s+(.*)$/m');
    $cpuName = smartMatch($cpuInfo, '/^model name\s+:\s+(.*)$/m');
    $cpuMicrocodeVersion = smartMatch($cpuInfo, '/^microcode\s+:\s+(.*)$/m');
    $cpuCount = smartNumber(smartMatch($cpuInfo, '/^cpu cores\s+:\s+([0-9]+)$/m'));
    $cpuMHz = smartNumber(smartMatch($cpuInfo, '/^cpu MHz\s+:\s+(.*)$/m'));

    $tempFlags = smartMatch($cpuInfo, '/^flags\s+:\s+(.*)$/m');
    if (is_string($tempFlags))
    {
        $cpuFlags = explode(" ", $tempFlags);
    }

    $tempBugs = smartMatch($cpuInfo, '/^bugs\s+:\s+(.*)$/m');
    if (is_string($tempBugs))
    {
        $cpuBugs = explode(" ", $tempBugs);
    }
}

// Load Average
$loadAvg = tryThings(array(
    // https://linuxwiki.de/proc/loadavg
    // https://linuxwiki.de/LoadAverage
    function()
    {
        $temp = smartFile("/proc/loadavg");

        if (is_string($temp))
        {
            $tempParts = explode(" ", $temp);
            $tempRun = explode("/", $tempParts[3]);
            return array(
                "1m" => (float)$tempParts[0],
                "5m" => (float)$tempParts[1],
                "15m" => (float)$tempParts[2],
                "runnableKernelSchedulingEntities" => (int)$tempRun[0],
                "allKernelSchedulingEntities" => (int)$tempRun[1],
            );
        }
    },
    function()
    {
        $temp = sys_getloadavg();
        return array(
            "1m" => $temp[0],
            "5m" => $temp[1],
            "15m" => $temp[2],
            "runnableKernelSchedulingEntities" => null,
            "allKernelSchedulingEntities" => null,
        );
    },
));

// CPU Statistics
// https://stackoverflow.com/a/1339596
$procStat = smartFile("/proc/stat");
$psInfo = null;
$bootTime = null;
$uptime = null;

if (is_string($procStat))
{
    // proc stats
    $psStatRgx = '/^cpu (:?\s*(?<user>[0-9]+))?(:?\s*(?<nice>[0-9]+))?(:?\s*(?<system>[0-9]+))?(:?\s*(?<idle>[0-9]+))?(:?\s*(?<ioWait>[0-9]+))?(:?\s*(?<irq>[0-9]+))?(:?\s*(?<softIrq>[0-9]+))?(:?\s*(?<steal>[0-9]+))?(:?\s*(?<guest>[0-9]+))?(:?\s*(?<guestNice>[0-9]+))?$/m';
    $matches = array();
    if (preg_match_all($psStatRgx, $procStat, $matches, PREG_SET_ORDER) > 0)
    {
        $psInfo = $matches[0];
    }

    // boot time
    $bootTime = smartNumber(smartMatch($procStat, '/^btime\s+([0-9]+)$/m'));

    if ($bootTime > 0)
    {
        $uptime = time() - $bootTime;
    }
}

$psStatFields = array('user', 'nice', 'system', 'idle', 'ioWait', 'irq', 'softIrq', 'steal', 'guest', 'guestNice');
$psInfo = ensureArray($psInfo, $psStatFields);

// Memory Info
$memInfo = smartFile("/proc/meminfo");

$memTotal = null;
$memFree = null;
$memAvailable = null;
$memBuffers = null;
$memCached = null;
$memCommitted = null;

if (is_string($memInfo))
{
    $memTotal = smartNumber(smartMatch($memInfo, '/^MemTotal:\s+([0-9]+)\s+kB$/m'));
    $memFree = smartNumber(smartMatch($memInfo, '/^MemFree:\s+([0-9]+)\s+kB$/m'));
    $memAvailable = smartNumber(smartMatch($memInfo, '/^MemAvailable:\s+([0-9]+)\s+kB$/m'));
    $memBuffers = smartNumber(smartMatch($memInfo, '/^Buffers:\s+([0-9]+)\s+kB$/m'));
    $memCached = smartNumber(smartMatch($memInfo, '/^Cached:\s+([0-9]+)\s+kB$/m'));
    $memCommitted = smartNumber(smartMatch($memInfo, '/^Committed_AS:\s+([0-9]+)\s+kB$/m'));
}


/**
 * Bring collected data together
 */
$data = array(
    "cpuInfo" => array(
        "vendor" => $cpuVendor,
        "model" => $cpuName,
        "microcodeVersion" => $cpuMicrocodeVersion,
        "bugs" => $cpuBugs,
        "coreCount" => $cpuCount,
        "coreMHz" => $cpuMHz,
        "flags" => $cpuFlags,
    ),
    "memoryInfo" => array(
        "totalKByte" => $memTotal,
        "freeKByte" => $memFree,
        "availableKByte" => $memAvailable,
        "buffersKByte" => $memBuffers,
        "cachedKByte" => $memCached,
        "committedKByte" => $memCommitted,
    ),
    "procStat" => $psInfo,
    "loadavg" => $loadAvg,
    "bootTimeUnixEpoch" => $bootTime,
    "uptimeSeconds" => $uptime,
    "runtimeOfThisScriptSeconds" => microtime(true) - $startTime,
);

if (isset($_GET['json']))
{
    header("Content-Type: application/json; charset=utf-8", true);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

// HTML Properties
$properties = array();

if (is_numeric($data['bootTimeUnixEpoch']))
{
    $tempTime = new DateTime();
    $tempTime->setTimestamp($data['bootTimeUnixEpoch']);
    $properties[] = array("Bootup Time", $tempTime->format('Y-m-d H:i'));
}

if (is_numeric($data['cpuInfo']['coreCount']))
{
    $properties[] = array("CPU Clock", $data['cpuInfo']['coreMHz']." MHz");
    $properties[] = array("CPU Core Count", $data['cpuInfo']['coreCount']);
}

if (is_numeric($data['loadavg']['1m']))
{
    $properties[] = array("Load Average", $data['loadavg']['1m']." (1m); ".$data['loadavg']['5m']." (5m); ".$data['loadavg']['15m']." (15m)");
}

if (is_numeric($data['loadavg']['5m']) && is_numeric($data['cpuInfo']['coreCount']))
{
    $usage = 100 / $data['cpuInfo']['coreCount'] * $data['loadavg']['5m'];
    $properties[] = array("System usage", number_format($usage, 2)."%");
}

if (is_numeric($data['memoryInfo']['totalKByte']))
{
    $total = $data['memoryInfo']['totalKByte'];
    $free = $data['memoryInfo']['freeKByte'];
    $buffers = $data['memoryInfo']['buffersKByte'];
    $cached = $data['memoryInfo']['cachedKByte'];
    $committed = $data['memoryInfo']['committedKByte'];

    $properties[] = array("Total Memory", number_format($total/1024, 2)." MByte");
    $properties[] = array("Free Memory", number_format($free/1024, 2)." MByte");
    $properties[] = array("Used Memory", number_format(($free+$buffers+$cached)/1024, 2)." MByte");
}

?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Some system informations</title>

        <style>
            body {
                font-size: 14px;
            }

            dt {
                font-weight: bold;
            }
        </style>
    </head>
    <body>

        <dl>
            <?php foreach($properties as $property): ?>
                <dt><?php echo $property[0]; ?>:</dt>
                <dd><?php echo $property[1]; ?></dd>
            <?php endforeach; ?>
        </dl>

        <hr>
        <a href="?json">Show Raw JSON (much more infos there!)</a> |
        <a href="https://github.com/perryflynn/sharedinfo" target="_blank">Code on github</a>

    </body>
</html>
