<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Warsaw');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
session_name('daily_fun_session');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

function reply(array $data, int $status=200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
function db(): PDO {
    static $pdo=null;
    if ($pdo instanceof PDO) return $pdo;
    if (!in_array('sqlite',PDO::getAvailableDrivers(),true)) throw new RuntimeException('PHP wymaga rozszerzenia PDO_SQLite.');
    $path=getenv('DAILY_FUN_DB') ?: dirname(__DIR__).DIRECTORY_SEPARATOR.'daily-fun.sqlite';
    $pdo=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $pdo->exec('PRAGMA foreign_keys=ON');
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL, username_key TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, created_at TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS game_plays(id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, game_id TEXT NOT NULL, played_on TEXT NOT NULL, UNIQUE(user_id,game_id,played_on))');
    $pdo->exec('CREATE INDEX IF NOT EXISTS game_plays_user_game_date ON game_plays(user_id,game_id,played_on)');
    return $pdo;
}
function user(PDO $pdo): ?array {
    if (empty($_SESSION['uid'])) return null;
    $q=$pdo->prepare('SELECT id,username FROM users WHERE id=?'); $q->execute([(int)$_SESSION['uid']]); $row=$q->fetch();
    if (!$row) { unset($_SESSION['uid']); return null; }
    return ['id'=>(int)$row['id'],'username'=>$row['username']];
}
function streaks(PDO $pdo, int $uid): array {
    $q=$pdo->prepare('SELECT game_id,played_on FROM game_plays WHERE user_id=? ORDER BY game_id,played_on DESC'); $q->execute([$uid]); $byGame=[];
    foreach ($q->fetchAll() as $row) $byGame[$row['game_id']][]=$row['played_on'];
    $today=new DateTimeImmutable('today'); $yesterday=$today->modify('-1 day'); $result=[];
    foreach ($byGame as $game=>$dates) {
        $cursor=$today;
        if ($dates[0]!==$today->format('Y-m-d')) {
            if ($dates[0]!==$yesterday->format('Y-m-d')) { $result[$game]=0; continue; }
            $cursor=$yesterday;
        }
        $count=0;
        foreach ($dates as $date) { if ($date!==$cursor->format('Y-m-d')) break; $count++; $cursor=$cursor->modify('-1 day'); }
        $result[$game]=$count;
    }
    return $result;
}
function account(PDO $pdo, array $person): array {
    return ['ok'=>true,'user'=>['username'=>$person['username']],'csrf'=>$_SESSION['csrf'],'streaks'=>streaks($pdo,$person['id'])];
}

try {
    $pdo=db(); $action=$_GET['action']??'';
    if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='me') {
        $person=user($pdo);
        reply(['ok'=>true,'csrf'=>$_SESSION['csrf'],'user'=>$person?['username'=>$person['username']]:null,'streaks'=>$person?streaks($pdo,$person['id']):(object)[]]);
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') reply(['error'=>'Nieobsługiwana metoda.'],405);
    $input=json_decode(file_get_contents('php://input')?:'',true);
    if (!is_array($input)) reply(['error'=>'Nieprawidłowe dane.'],400);
    $token=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
    if (!is_string($token)||!hash_equals((string)$_SESSION['csrf'],$token)) reply(['error'=>'Odśwież stronę i spróbuj ponownie.'],403);

    if ($action==='register'||$action==='login') {
        $name=trim((string)($input['username']??'')); $password=(string)($input['password']??'');
        if (!preg_match('/^[\p{L}\p{N}_-]{2,24}$/u',$name)) reply(['error'=>'Login: 2–24 znaki, litery, cyfry, _ lub -.'],422);
        if (strlen($password)<8||strlen($password)>200) reply(['error'=>'Hasło musi mieć co najmniej 8 znaków.'],422);
        $key=function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
        if ($action==='register') {
            $q=$pdo->prepare('SELECT id FROM users WHERE username_key=?'); $q->execute([$key]);
            if ($q->fetch()) reply(['error'=>'Taki login jest już zajęty.'],409);
            $q=$pdo->prepare('INSERT INTO users(username,username_key,password_hash,created_at) VALUES(?,?,?,?)');
            $q->execute([$name,$key,password_hash($password,PASSWORD_DEFAULT),date('c')]); $uid=(int)$pdo->lastInsertId();
        } else {
            $q=$pdo->prepare('SELECT id,username,password_hash FROM users WHERE username_key=?'); $q->execute([$key]); $row=$q->fetch();
            if (!$row||!password_verify($password,$row['password_hash'])) reply(['error'=>'Nieprawidłowy login lub hasło.'],401);
            $uid=(int)$row['id']; $name=$row['username'];
        }
        session_regenerate_id(true); $_SESSION['uid']=$uid; $_SESSION['csrf']=bin2hex(random_bytes(32));
        reply(account($pdo,['id'=>$uid,'username'=>$name]));
    }
    $person=user($pdo);
    if (!$person) reply(['error'=>'Zaloguj się, aby zapisywać serie.'],401);
    if ($action==='logout') {
        unset($_SESSION['uid']); session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32));
        reply(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
    }
    if ($action==='play') {
        $game=(string)($input['game_id']??'');
        $allowed=['Catescape','Krillion','enclose.horse','Primaries','Wonderhop','Seedle','Wordle','Connections','Songless','VocalStim','100hitów','Dialed Sound','Dialed Tempo','Loldle','Isaac Connect','Nerdle','Truth Sweeper','Metazooa','Size It Up','MayHex POUR','Hexcodle','Dialed Color','Anything Grid','TimeGuessr','Borderline','The Hex Hunt','Globle','Does Monday Work?','Who Farted','SUMS','Mini Golf','Infinite Craft'];
        if (!in_array($game,$allowed,true)) reply(['error'=>'Nieznana gra.'],422);
        $q=$pdo->prepare('INSERT OR IGNORE INTO game_plays(user_id,game_id,played_on) VALUES(?,?,?)'); $q->execute([$person['id'],$game,date('Y-m-d')]);
        reply(['ok'=>true,'streaks'=>streaks($pdo,$person['id'])]);
    }
    reply(['error'=>'Nieznana akcja.'],404);
} catch (Throwable $e) {
    error_log('daily fun api: '.$e->getMessage());
    reply(['error'=>'Baza kont jest niedostępna. Sprawdź PHP i rozszerzenie PDO_SQLite.'],500);
}
