<?php
$dsn = 'mysql:dbname=tb270482db;host=localhost';
$user = 'tb-270482';
$password = 'fw3AuCgNAR';

session_start();
$pdo = new PDO($dsn, $user, $password, [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// ===== 初回だけテーブル自動作成（存在すればスキップ） =====
// 旧アプリの posts は触らない！ 新日記は diary_posts を使う
$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
);

$pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS diary_posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  content TEXT NOT NULL,
  image_path VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_user_created (user_id, created_at),
  CONSTRAINT fk_diary_posts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL
);

// ===== helpers =====
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
function csrf_field(){ echo '<input type="hidden" name="csrf" value="'.h($_SESSION['csrf']).'">'; }
function check_csrf(){ if(($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) exit('CSRF token mismatch'); }
function flash($msg=null){
  if($msg!==null){ $_SESSION['flash']=$msg; return; }
  if(isset($_SESSION['flash'])){ echo '<div class="flash">'.h($_SESSION['flash']).'</div>'; unset($_SESSION['flash']); }
}
function me($pdo){
  if(!isset($_SESSION['uid'])) return null;
  $s=$pdo->prepare('SELECT * FROM users WHERE id=?'); $s->execute([$_SESSION['uid']]);
  return $s->fetch();
}

$action = $_GET['action'] ?? 'home';

// ===== 認証 =====
if($action==='register' && $_SERVER['REQUEST_METHOD']==='POST'){
  check_csrf();
  $name=trim($_POST['name']??''); $email=trim($_POST['email']??''); $pass=(string)($_POST['password']??'');
  if(!$name || !$email || !$pass){ flash('未入力があります'); header('Location:?'); exit; }
  try{
    $pdo->prepare('INSERT INTO users(email,password_hash,display_name) VALUES(?,?,?)')
        ->execute([$email, password_hash($pass,PASSWORD_DEFAULT), $name]);
  }catch(Exception $e){ flash('登録に失敗（既に使われている可能性）'); header('Location:?'); exit; }
  $_SESSION['uid']=(int)$pdo->lastInsertId(); flash('登録しました'); header('Location:?'); exit;
}
if($action==='login' && $_SERVER['REQUEST_METHOD']==='POST'){
  check_csrf();
  $email=trim($_POST['email']??''); $pass=(string)($_POST['password']??'');
  $s=$pdo->prepare('SELECT * FROM users WHERE email=?'); $s->execute([$email]); $u=$s->fetch();
  if($u && password_verify($pass,$u['password_hash'])){ $_SESSION['uid']=(int)$u['id']; flash('ログインしました'); }
  else { flash('メールまたはパスワードが違います'); }
  header('Location:?'); exit;
}
if($action==='logout'){ session_destroy(); header('Location:?'); exit; }

$me = me($pdo);

// ===== 個人日記：投稿 作成/編集/削除 =====
if($me && $action==='new_post' && $_SERVER['REQUEST_METHOD']==='POST'){
  check_csrf();
  $content=trim($_POST['content']??'');
  $image_path=null;
  if(!empty($_FILES['image']['name'])){
    if(!is_uploaded_file($_FILES['image']['tmp_name'])){ flash('アップロード失敗'); header('Location:?'); exit; }
    $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file($_FILES['image']['tmp_name']); $ext=null;
    if($mime==='image/jpeg')$ext='jpg'; elseif($mime==='image/png')$ext='png'; elseif($mime==='image/gif')$ext='gif'; else { flash('JPEG/PNG/GIFのみ'); header('Location:?'); exit; }
    if($_FILES['image']['size']>5*1024*1024){ flash('画像は5MBまで'); header('Location:?'); exit; }
    if(!is_dir(__DIR__.'/uploads')) mkdir(__DIR__.'/uploads',0777,true);
    $filename=uniqid('img_',true).'.'.$ext; $dest=__DIR__.'/uploads/'.$filename;
    if(!move_uploaded_file($_FILES['image']['tmp_name'],$dest)){ flash('保存失敗'); header('Location:?'); exit; }
    $image_path='uploads/'.$filename;
  }
  if(!$content && !$image_path){ flash('本文か画像のどちらかは必須'); header('Location:?'); exit; }
  $pdo->prepare('INSERT INTO diary_posts(user_id,content,image_path) VALUES(?,?,?)')
      ->execute([$me['id'],$content,$image_path]);
  flash('投稿しました'); header('Location:?'); exit;
}

if($me && $action==='edit_post' && $_SERVER['REQUEST_METHOD']==='POST'){
  check_csrf();
  $id=(int)($_POST['id']??0);
  $s=$pdo->prepare('SELECT * FROM diary_posts WHERE id=? AND user_id=?'); $s->execute([$id,$me['id']]); $p=$s->fetch();
  if(!$p){ flash('編集不可'); header('Location:?'); exit; }
  $content=trim($_POST['content']??''); $image_path=$p['image_path'];
  if(isset($_POST['remove_image'])){ if($image_path && file_exists(__DIR__.'/'.$image_path)) unlink(__DIR__.'/'.$image_path); $image_path=null; }
  if(!empty($_FILES['image']['name'])){
    if(!is_uploaded_file($_FILES['image']['tmp_name'])){ flash('アップロード失敗'); header('Location:?'); exit; }
    $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file($_FILES['image']['tmp_name']); $ext=null;
    if($mime==='image/jpeg')$ext='jpg'; elseif($mime==='image/png')$ext='png'; elseif($mime==='image/gif')$ext='gif'; else { flash('JPEG/PNG/GIFのみ'); header('Location:?'); exit; }
    if($_FILES['image']['size']>5*1024*1024){ flash('画像は5MBまで'); header('Location:?'); exit; }
    $filename=uniqid('img_',true).'.'.$ext; $dest=__DIR__.'/uploads/'.$filename;
    if(!move_uploaded_file($_FILES['image']['tmp_name'],$dest)){ flash('保存失敗'); header('Location:?'); exit; }
    if($image_path && file_exists(__DIR__.'/'.$image_path)) unlink(__DIR__.'/'.$image_path);
    $image_path='uploads/'.$filename;
  }
  if(!$content && !$image_path){ flash('本文か画像のどちらかは必須'); header('Location:?'); exit; }
  $pdo->prepare('UPDATE diary_posts SET content=?, image_path=? WHERE id=? AND user_id=?')
      ->execute([$content,$image_path,$id,$me['id']]);
  flash('更新しました'); header('Location:?'); exit;
}

if($me && $action==='delete_post' && $_SERVER['REQUEST_METHOD']==='POST'){
  check_csrf();
  $id=(int)($_POST['id']??0);
  $s=$pdo->prepare('SELECT * FROM diary_posts WHERE id=? AND user_id=?'); $s->execute([$id,$me['id']]); $p=$s->fetch();
  if($p){ if($p['image_path'] && file_exists(__DIR__.'/'.$p['image_path'])) unlink(__DIR__.'/'.$p['image_path']);
    $pdo->prepare('DELETE FROM diary_posts WHERE id=?')->execute([$id]); flash('削除しました'); }
  header('Location:?'); exit;
}

// ===== ここからHTML =====
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>シンプル個人日記</title>
  <style>
    *{box-sizing:border-box} body{font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0;background:#f7f7f9;color:#111}
    header{background:#1f2937;color:#fff;padding:14px 18px;display:flex;align-items:center;justify-content:space-between}
    a{color:#2563eb;text-decoration:none} a:hover{text-decoration:underline}
    main{max-width:820px;margin:20px auto;padding:0 14px 40px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px;margin:12px 0}
    .muted{color:#6b7280} input[type=text],input[type=password],input[type=file],textarea{width:100%;padding:10px;border:1px solid #d1d5db;border-radius:8px;margin-top:6px}
    textarea{min-height:120px} button{border:0;background:#2563eb;color:#fff;padding:10px 14px;border-radius:8px;cursor:pointer}
    button.secondary{background:#6b7280} button.danger{background:#dc2626}
    .row{display:flex;gap:12px;flex-wrap:wrap}.row>.col{flex:1}
    img.post{max-width:100%;border-radius:10px;margin-top:8px;border:1px solid #eee}
    .flash{background:#ecfeff;border:1px solid #a5f3fc;padding:10px;border-radius:8px;margin:10px 0}
  </style>
</head>
<body>
<header>
  <div><strong>シンプル個人日記</strong></div>
  <div>
    <?php if($me): ?>
      ようこそ、<?=h($me['display_name'])?> さん
      <form style="display:inline" action="?action=logout" method="get">
        <input type="hidden" name="action" value="logout">
        <button class="secondary" type="submit">ログアウト</button>
      </form>
    <?php endif; ?>
  </div>
</header>
<main>
  <?php flash(); ?>
  <?php if(!$me): ?>
    <div class="row">
      <div class="col">
        <div class="card">
          <h2>ログイン</h2>
          <form method="post" action="?action=login">
            <?php csrf_field(); ?>
            <label>メール<input type="text" name="email"></label>
            <label>パスワード<input type="password" name="password"></label>
            <div style="height:10px"></div><button type="submit">ログイン</button>
          </form>
        </div>
      </div>
      <div class="col">
        <div class="card">
          <h2>新規登録</h2>
          <form method="post" action="?action=register">
            <?php csrf_field(); ?>
            <label>表示名<input type="text" name="name" required></label>
            <label>メール<input type="text" name="email" required></label>
            <label>パスワード<input type="password" name="password" required></label>
            <div style="height:10px"></div><button type="submit">登録</button>
          </form>
        </div>
      </div>
    </div>
  <?php else: ?>
    <div class="card">
      <h3>マイ日記に投稿</h3>
      <form method="post" action="?action=new_post" enctype="multipart/form-data">
        <?php csrf_field(); ?>
        <label>本文<textarea name="content" placeholder="今日の出来事やメモ…"></textarea></label>
        <label>写真（任意）<input type="file" name="image" accept="image/*"></label>
        <div style="height:10px"></div><button type="submit">投稿する</button>
      </form>
    </div>

    <div class="card">
      <h3>投稿一覧</h3>
      <?php
        $s=$pdo->prepare('SELECT * FROM diary_posts WHERE user_id=? ORDER BY created_at DESC');
        $s->execute([$me['id']]); $posts=$s->fetchAll();
        if(!$posts){ echo '<p class="muted">まだ投稿はありません。</p>'; }
        foreach($posts as $p):
      ?>
        <div class="card" style="background:#fafafa;margin:12px 0">
          <div class="muted"><?=h($p['created_at'])?><?php if($p['updated_at']!==$p['created_at']): ?> · 更新: <?=h($p['updated_at'])?><?php endif; ?></div>
          <div><?=nl2br(h($p['content']))?></div>
          <?php if($p['image_path']): ?><img class="post" src="<?=h($p['image_path'])?>" alt=""><?php endif; ?>
          <details>
            <summary>編集・削除</summary>
            <form method="post" action="?action=edit_post" enctype="multipart/form-data">
              <?php csrf_field(); ?>
              <input type="hidden" name="id" value="<?=$p['id']?>">
              <label>本文<textarea name="content"><?=h($p['content'])?></textarea></label>
              <label>写真の更新（任意）<input type="file" name="image" accept="image/*"></label>
              <?php if($p['image_path']): ?><label><input type="checkbox" name="remove_image" value="1"> 写真を削除する</label><?php endif; ?>
              <div style="height:10px"></div><button type="submit">更新</button>
            </form>
            <form method="post" action="?action=delete_post" onsubmit="return confirm('本当に削除しますか？');">
              <?php csrf_field(); ?>
              <input type="hidden" name="id" value="<?=$p['id']?>">
              <button class="danger" type="submit">投稿を削除</button>
            </form>
           </details>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
</body>
</html>