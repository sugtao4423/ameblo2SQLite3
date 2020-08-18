<?php
declare(strict_types=1);

if(!isset($argv[1])){
    echo "Please set User ID\n";
    exit(1);
}

$userId = $argv[1];
define('BASE_URL', "https://ameblo.jp/${userId}");
define('SAVE_DIR', __DIR__);
define('SQLITE3_DB', SAVE_DIR . "/${userId}.sqlite3");
define('MEDIA_SAVE_DIR', SAVE_DIR . "/${userId}");

@mkdir(MEDIA_SAVE_DIR);

$pdo = new PDO('sqlite:' . SQLITE3_DB);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE IF NOT EXISTS '${userId}' (entry_id INTEGER, entry_title TEXT, entry_text TEXT, entry_medias TEXT, is_amember INTEGER, entry_created_datetime INTEGER)");
$lastEntryId = ($row = $pdo->query("SELECT entry_id FROM '${userId}' ORDER BY entry_created_datetime DESC LIMIT 1")->fetch()) === false ? 0 : $row['entry_id'];

$maxPageCount = 1;
$entries = [];
for($i = 1; $i <= $maxPageCount; $i++){
    echo "\rLoading page: ${i} /${maxPageCount} ";
    $json = extractJson(ameblo_get_contents(BASE_URL . "/page-${i}.html"));
    if(!isset($json['entryState'])){
        echo "Failed get entries...\n";
        echo "Retrying...\n";
        $i--;
        sleep(1);
        continue;
    }
    $blogId = $json['bloggerState']['bloggerMap'][$userId]['blog'];
    $maxPageCount = $json['entryState']['pcBlogTopPageMap']["${blogId}/${i}"]['paging']['max_page'];

    $breakFlag = false;
    $entryIds = $json['entryState']['pcBlogTopPageMap']["${blogId}/${i}"]['data'];
    foreach($entryIds as $entryId){
        if($lastEntryId == $entryId){
            $breakFlag = true;
            break;
        }
        $entryText = $json['entryState']['entryMap'][$entryId]['entry_text'] ?? '';
        $medias = extractMedias($entryText);
        saveMedias($entryId, $medias);
        $item = [
            'entry_id' => $entryId,
            'entry_title' => $json['entryState']['entryMap'][$entryId]['entry_title'],
            'entry_text' => $entryText,
            'entry_medias' => $medias,
            'is_amember' => $json['entryState']['entryMap'][$entryId]['publish_flg'] == 'amember',
            'entry_created_datetime' => strtotime($json['entryState']['entryMap'][$entryId]['entry_created_datetime'])
        ];
        $entries[] = $item;
    }
    if($breakFlag){
        break;
    }
}

$entries = array_reverse($entries);
$insertSql = "INSERT INTO '${userId}' VALUES (?, ?, ?, ?, ?, ?)";
$pdo->beginTransaction();
foreach($entries as $entry){
    $stmt = $pdo->prepare($insertSql);
    $stmt->bindValue(1, $entry['entry_id'], PDO::PARAM_INT);
    $stmt->bindValue(2, $entry['entry_title'], PDO::PARAM_STR);
    $stmt->bindValue(3, $entry['entry_text'], PDO::PARAM_STR);
    $stmt->bindValue(4, implode(',', $entry['entry_medias']), PDO::PARAM_STR);
    $stmt->bindValue(5, (int)$entry['is_amember'], PDO::PARAM_INT);
    $stmt->bindValue(6, $entry['entry_created_datetime'], PDO::PARAM_INT);
    $stmt->execute();
}
$pdo->commit();
$pdo = null;

echo "\n";

function extractJson(string $html): array{
    $isMatch = preg_match('|<script>window\.INIT_DATA=(.*);window\.RESOURCE_BASE_URL|', $html, $m);
    if($isMatch !== 1){
        echo 'Error: failed extract json';
        exit(1);
    }
    return json_decode($m[1], true);
}

function extractMedias(string $text): array{
    if(preg_match_all('|https?://stat\.ameba\.jp/user_images/.+/.+\.\w+?|U', $text, $matches) !== false){
        $matches = $matches[0];
        $matches = array_map(function($v){
            return str_replace('http://', 'https://', $v);
        }, $matches);
        $matches = array_unique($matches);
        $matches = array_values($matches);
        return $matches;
    }
    return [];
}

function saveMedias(int $entryId, array $medias){
    foreach($medias as $media){
        $path = MEDIA_SAVE_DIR . "/${entryId}-" . basename($media);
        $file = ameblo_get_contents($media);
        if($file !== '' && file_put_contents($path, $file) === false){
            error_log("Error: save failed: ${media}");
        }
    }
}

function ameblo_get_contents(string $url, int $retryCount = 0): string{
    sleep(1);
    $str = @file_get_contents($url, false, stream_context_create([
        'http' => [
            'header' => "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_4) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/67.0.3396.87 Safari/537.36\r\n"
        ]
    ]));
    if($str === false){
        if($retryCount >= 5){
            error_log("{$http_response_header[0]}: ${url}");
            return '';
        }
        return ameblo_get_contents($url, ++$retryCount);
    }
    return $str;
}
