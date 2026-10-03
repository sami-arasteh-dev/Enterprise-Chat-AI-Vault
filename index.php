<?php
declare(strict_types=1);
session_start();

// فراخوانی دیتابیس با قابلیت مدیریت Namespace
require_once 'VaultDB.php';
if (class_exists('App\Database\VaultDB')) {
    class_alias('App\Database\VaultDB', 'VaultDB');
}

// --- پیکربندی سیستم ---
$storageDir = __DIR__ . '/storage';
$uploadsDir = __DIR__ . '/uploads'; // 📁 پوشه جدید برای آپلودهای واقعی

if (!is_dir($storageDir)) mkdir($storageDir, 0777, true);
if (!is_dir($uploadsDir)) mkdir($uploadsDir, 0777, true);

$dbPassword = 'SuperSecretEnterprisePassword99!';
$apiKey = 'sk-K8DqLbYCVA4AZSKF72pDRM8vBxrWagpMLH3L8FXUR8LsSgC7';
$apiBaseUrl = 'https://api.gapgpt.app/v1/chat/completions';

// مقداردهی دیتابیس مرکزی
try {
    $db = new VaultDB($storageDir, 'core_system', $dbPassword);
    $db->createTable('users');
} catch (Exception $e) {
    die("خطای بحرانی دیتابیس: " . $e->getMessage());
}

// -----------------------------------------------------------------------------
// بک‌اند سیستم (API)
// -----------------------------------------------------------------------------
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['api'];
    
    // دریافت ورودی‌ها (پشتیبانی از JSON و FormData)
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    try {
        // ۱. احراز هویت
        if ($action === 'auth') {
            $username = strtolower(trim($input['username'] ?? ''));
            $fullname = trim($input['fullname'] ?? '');
            $password = $input['password'] ?? '';

            if (empty($username) || empty($password)) throw new Exception("اطلاعات ناقص است.");

            $users = $db->select('users', ['username' => $username]);
            
            if (empty($users)) {
                if (empty($fullname)) throw new Exception("برای ثبت نام نام کامل الزامیست.");
                $db->insert('users', [
                    'username' => $username,
                    'fullname' => $fullname,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'last_seen' => time()
                ]);
                echo json_encode(['status' => 'success', 'message' => 'ثبت نام با موفقیت انجام شد.', 'fullname' => $fullname]);
            } else {
                $user = $users[0];
                if (!password_verify($password, $user['password'])) {
                    throw new Exception("رمز عبور اشتباه است.");
                }
                $db->update('users', ['username' => $username], ['last_seen' => time()]);
                echo json_encode(['status' => 'success', 'message' => 'ورود موفقیت آمیز.', 'fullname' => $user['fullname']]);
            }
            exit;
        }

        // ۲. پیشنهاد خودکار (Autocomplete)
        if ($action === 'autocomplete') {
            $query = strtolower(trim($input['query'] ?? ''));
            $me = $input['me'] ?? '';
            $matches = [];
            
            if (!empty($query)) {
                $allUsers = $db->select('users');
                foreach ($allUsers as $u) {
                    if ($u['username'] !== $me) {
                        if (str_contains(strtolower($u['username']), $query) || str_contains(strtolower($u['fullname']), $query)) {
                            $matches[] = ['username' => $u['username'], 'fullname' => $u['fullname']];
                        }
                    }
                }
            }
            echo json_encode(['status' => 'success', 'users' => $matches]);
            exit;
        }

        // ۳. پینگ و وضعیت آنلاین
        if ($action === 'ping') {
            $username = $input['username'] ?? '';
            if ($username) $db->update('users', ['username' => $username], ['last_seen' => time()]);
            
            $allUsers = $db->select('users');
            $contacts = [];
            foreach ($allUsers as $u) {
                if ($u['username'] !== $username) {
                    $isOnline = (time() - $u['last_seen']) < 15;
                    $contacts[] = ['username' => $u['username'], 'fullname' => $u['fullname'], 'online' => $isOnline];
                }
            }
            echo json_encode(['status' => 'success', 'contacts' => $contacts]);
            exit;
        }

        // ۴. دریافت پیام‌ها
        if ($action === 'get_messages') {
            $user1 = $input['user1'];
            $user2 = $input['user2'];
            
            if ($user2 === 'ai_assistant') {
                $room = "chat_ai_{$user1}";
            } else {
                $room = "chat_" . (strcmp($user1, $user2) < 0 ? "{$user1}_{$user2}" : "{$user2}_{$user1}");
            }
            
            $chatDb = new VaultDB($storageDir, $room, $dbPassword);
            $chatDb->createTable('messages');
            $messages = $chatDb->select('messages');
            
            echo json_encode(['status' => 'success', 'messages' => $messages]);
            exit;
        }

        // ۵. ارسال پیام و هوش مصنوعی
        if ($action === 'send_message') {
            $sender = $input['sender'];
            $receiver = $input['receiver'];
            $text = $input['text'];
            $aiModels = $input['ai_models'] ?? [];

            if ($receiver === 'ai_assistant') {
                $room = "chat_ai_{$sender}";
                if (empty($aiModels)) $aiModels = ['gpt-4o']; 
            } else {
                $room = "chat_" . (strcmp($sender, $receiver) < 0 ? "{$sender}_{$receiver}" : "{$receiver}_{$sender}");
            }

            $chatDb = new VaultDB($storageDir, $room, $dbPassword);
            $chatDb->createTable('messages');
            
            $chatDb->insert('messages', [
                'sender' => $sender,
                'text' => $text,
                'timestamp' => time()
            ]);

            if (!empty($aiModels)) {
                $allMsgs = $chatDb->select('messages');
                $last20 = array_slice($allMsgs, -20);
                
                $aiMessages = [];
                foreach ($last20 as $m) {
                    $role = ($m['sender'] === $sender) ? 'user' : 'assistant';
                    // حذف تگ‌های HTML موقع ارسال به AI که گیج نشه
                    $cleanText = strip_tags($m['text']); 
                    $aiMessages[] = ['role' => $role, 'content' => $cleanText];
                }

                foreach ($aiModels as $model) {
                    $ch = curl_init($apiBaseUrl);
                    $payload = json_encode(['model' => $model, 'messages' => $aiMessages]);
                    
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Content-Type: application/json',
                        'Authorization: Bearer ' . $apiKey
                    ]);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
                    
                    $response = curl_exec($ch);
                    curl_close($ch);
                    
                    if ($response) {
                        $resData = json_decode($response, true);
                        $aiReply = $resData['choices'][0]['message']['content'] ?? "خطا در دریافت پاسخ از $model";
                        
                        $chatDb->insert('messages', [
                            'sender' => 'AI: ' . $model,
                            'text' => nl2br(htmlspecialchars($aiReply)), // ایمن سازی خروجی هوش مصنوعی
                            'timestamp' => time()
                        ]);
                    }
                }
            }

            echo json_encode(['status' => 'success']);
            exit;
        }

        // ۶. 🚀 سیستم آپلود فایل واقعی و اینترپرایز
        if ($action === 'upload_file') {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("خطا در دریافت فایل از کلاینت!");
            }

            $file = $_FILES['file'];
            $maxSize = 10 * 1024 * 1024; // حداکثر 10 مگابایت
            
            if ($file['size'] > $maxSize) {
                throw new Exception("حجم فایل نباید بیشتر از 10 مگابایت باشد.");
            }

            $originalName = basename($file['name']);
            $fileInfo = pathinfo($originalName);
            $extension = strtolower($fileInfo['extension'] ?? '');

            // لیست سفید پسوندهای مجاز (امنیت گرید نظامی!)
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip', 'rar', 'mp3', 'mp4'];
            $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

            if (!in_array($extension, $allowedExtensions)) {
                throw new Exception("نوع فایل غیرمجاز است! خطر امنیتی مسدود شد.");
            }

            // تولید یک نام رندوم و امن برای جلوگیری از اوررایت شدن
            $safeFileName = bin2hex(random_bytes(8)) . '_' . time() . '.' . $extension;
            $destination = $uploadsDir . '/' . $safeFileName;

            if (move_uploaded_file($file['tmp_name'], $destination)) {
                $fileUrl = 'uploads/' . $safeFileName;
                $isImage = in_array($extension, $imageExtensions);
                
                echo json_encode([
                    'status' => 'success', 
                    'file_url' => $fileUrl, 
                    'file_name' => $originalName,
                    'is_image' => $isImage
                ]);
            } else {
                throw new Exception("خطا در ذخیره‌سازی فایل روی سرور.");
            }
            exit;
        }

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enterprise Chat & AI Vault</title>
    <style>
        /* 🎨 استایل‌های سازمانی */
        :root {
            --bg-dark: #0f172a; --bg-panel: #1e293b; --primary: #3b82f6; --primary-hover: #2563eb;
            --text-main: #f8fafc; --text-muted: #94a3b8; --border: #334155;
            --online: #10b981; --offline: #ef4444; --ai-active: #8b5cf6;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Tahoma, sans-serif; }
        body { background-color: var(--bg-dark); color: var(--text-main); display: flex; height: 100vh; overflow: hidden; }
        
        #auth-screen { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: var(--bg-dark); display: flex; justify-content: center; align-items: center; z-index: 1000; }
        .auth-box { background: var(--bg-panel); padding: 40px; border-radius: 12px; width: 350px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); text-align: center; border: 1px solid var(--border); }
        .auth-box h2 { margin-bottom: 20px; color: var(--primary); }
        .input-group { margin-bottom: 15px; text-align: right; }
        .input-group input { width: 100%; padding: 12px; border-radius: 6px; border: 1px solid var(--border); background: var(--bg-dark); color: var(--text-main); outline: none; transition: 0.3s; }
        .input-group input:focus { border-color: var(--primary); }
        button { width: 100%; padding: 12px; background: var(--primary); color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 16px; transition: 0.3s; }
        button:hover { background: var(--primary-hover); }

        #chat-app { display: none; width: 100%; height: 100%; flex-direction: row; }
        
        .sidebar { width: 300px; background: var(--bg-panel); border-left: 1px solid var(--border); display: flex; flex-direction: column; position: relative; }
        
        /* استایل‌های جستجو */
        .search-container { position: relative; padding: 15px; border-bottom: 1px solid var(--border); }
        .search-container input { width: 100%; padding: 10px; border-radius: 20px; border: 1px solid var(--border); background: var(--bg-dark); color: white; text-align: right; outline: none; transition: 0.3s; }
        .search-container input:focus { border-color: var(--primary); }
        .autocomplete-dropdown { position: absolute; top: 100%; left: 15px; right: 15px; background: var(--bg-dark); border: 1px solid var(--border); border-radius: 0 0 10px 10px; max-height: 200px; overflow-y: auto; z-index: 50; display: none; box-shadow: 0 5px 15px rgba(0,0,0,0.5); }
        .autocomplete-item { padding: 10px 15px; cursor: pointer; border-bottom: 1px solid var(--border); font-size: 13px; }
        .autocomplete-item:hover { background: var(--bg-panel); color: var(--primary); }
        
        .ai-direct-btn { margin: 10px; padding: 12px; background: linear-gradient(45deg, #8b5cf6, #3b82f6); border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-weight: bold; box-shadow: 0 4px 10px rgba(139, 92, 246, 0.3); transition: 0.3s; }
        .ai-direct-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(139, 92, 246, 0.5); }

        .contacts { flex: 1; overflow-y: auto; padding: 10px; }
        .contact-item { padding: 12px; margin-bottom: 8px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; transition: 0.2s; background: var(--bg-dark); border: 1px solid transparent; }
        .contact-item:hover { background: #2dd4bf20; border-color: var(--border); }
        .status-dot { width: 10px; height: 10px; border-radius: 50%; margin-left: 10px; }
        .online { background: var(--online); box-shadow: 0 0 8px var(--online); }
        .offline { background: var(--offline); }
        .contact-name { flex: 1; font-weight: bold; font-size: 14px; }

        .main-chat { flex: 1; display: flex; flex-direction: column; background: var(--bg-dark); }
        
        .chat-header { height: 60px; background: var(--bg-panel); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; padding: 0 15px; }
        .tabs { display: flex; gap: 5px; flex: 1; overflow-x: auto; }
        .tab { padding: 8px 15px; background: var(--bg-dark); border: 1px solid var(--border); border-radius: 6px 6px 0 0; cursor: pointer; display: flex; align-items: center; gap: 8px; border-bottom: none; opacity: 0.6; white-space: nowrap; font-size: 14px;}
        .tab.active { background: var(--primary); color: white; opacity: 1; border-color: var(--primary); }
        .tab.ai-tab { background: #3b0764; border-color: var(--ai-active); }
        .close-tab { font-size: 12px; color: #ffcccc; cursor: pointer; padding: 2px; }
        .close-tab:hover { color: white; background: rgba(255,0,0,0.5); border-radius: 50%; }
        
        .ai-tools { display: flex; gap: 10px; }
        .ai-icon { width: 35px; height: 35px; border-radius: 50%; display: flex; justify-content: center; align-items: center; cursor: pointer; border: 2px solid var(--border); background: var(--bg-dark); transition: 0.3s; font-size: 12px; font-weight: bold; color: var(--text-muted); user-select: none; }
        .ai-icon.active { border-color: var(--ai-active); background: #8b5cf620; color: var(--ai-active); box-shadow: 0 0 10px var(--ai-active); }

        .messages-area { flex: 1; padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 15px; }
        .message { max-width: 65%; padding: 12px 18px; border-radius: 12px; position: relative; line-height: 1.5; font-size: 14px; word-wrap: break-word; }
        .msg-me { background: var(--primary); align-self: flex-start; border-bottom-right-radius: 2px; }
        .msg-other { background: var(--bg-panel); align-self: flex-end; border-bottom-left-radius: 2px; border: 1px solid var(--border); }
        .msg-ai { background: #3b0764; align-self: flex-end; border-bottom-left-radius: 2px; border: 1px solid var(--ai-active); box-shadow: inset 0 0 10px rgba(139,92,246,0.2); }
        .msg-time { font-size: 10px; opacity: 0.7; margin-top: 5px; display: block; text-align: right; }
        .msg-sender { font-size: 11px; font-weight: bold; margin-bottom: 5px; opacity: 0.8; color: #fbbf24; }
        
        /* استایل‌های مربوط به آپلودها در پیام */
        .chat-image { max-width: 250px; border-radius: 8px; margin-top: 8px; border: 2px solid rgba(255,255,255,0.2); cursor: pointer; transition: 0.3s;}
        .chat-image:hover { opacity: 0.9; }
        .chat-file-link { display: inline-flex; align-items: center; gap: 5px; background: rgba(0,0,0,0.2); padding: 8px 12px; border-radius: 6px; color: #60a5fa; text-decoration: none; margin-top: 8px; font-weight: bold; border: 1px solid rgba(255,255,255,0.1); }
        .chat-file-link:hover { background: rgba(0,0,0,0.4); text-decoration: underline; }

        .typing-indicator { font-size: 12px; color: var(--text-muted); font-style: italic; display: none; align-self: flex-end; margin-bottom: 10px; padding: 0 20px; }

        .chat-input-area { padding: 15px; background: var(--bg-panel); border-top: 1px solid var(--border); display: flex; gap: 10px; align-items: center; }
        .toolbar { display: flex; gap: 5px; color: var(--text-muted); cursor: pointer; }
        .toolbar span { padding: 8px; border-radius: 4px; transition: 0.2s; font-size: 18px; }
        .toolbar span:hover { background: var(--bg-dark); color: white; }
        .chat-input-area input[type="text"] { flex: 1; padding: 12px; border-radius: 20px; border: 1px solid var(--border); background: var(--bg-dark); color: white; outline: none; }
        .btn-send { width: 50px; height: 42px; border-radius: 20px; display: flex; justify-content: center; align-items: center; font-size: 18px;}
        
        #upload-spinner { display: none; font-size: 12px; color: var(--online); animation: blink 1s infinite; }
        @keyframes blink { 0% {opacity: 1;} 50% {opacity: 0.5;} 100% {opacity: 1;} }
    </style>
</head>
<body>

    <div id="auth-screen">
        <div class="auth-box">
            <h2>پورتال ورودی سیستم</h2>
            <div class="input-group"><input type="text" id="login-user" placeholder="نام کاربری (انگلیسی)..." autocomplete="off"></div>
            <div class="input-group"><input type="text" id="login-name" placeholder="نام کامل (برای تازه واردها)..." autocomplete="off"></div>
            <div class="input-group"><input type="password" id="login-pass" placeholder="رمز عبور..." onkeypress="window.handleAuthEnter(event)"></div>
            <button onclick="window.authenticate()">ورود / ثبت نام</button>
            <p id="auth-msg" style="color: #ef4444; margin-top: 15px; font-size: 13px;"></p>
        </div>
    </div>

    <div id="chat-app">
        <div class="sidebar">
            <div class="search-container">
                <input type="text" id="search-input" placeholder="جستجوی کاربران..." oninput="window.handleAutocomplete(this.value)">
                <div class="autocomplete-dropdown" id="autocomplete-list"></div>
            </div>
            <div class="ai-direct-btn" onclick="window.openChatTab('ai_assistant', '✨ دستیار هوشمند AI')">
                ✨ مکالمه مستقیم با هوش مصنوعی
            </div>
            <div class="contacts" id="contacts-list"></div>
        </div>

        <div class="main-chat">
            <div class="chat-header">
                <div class="tabs" id="tabs-container">
                    <div class="tab active" onclick="window.clearActiveChat()">پنل اصلی</div>
                </div>
                <div class="ai-tools" title="هوش مصنوعی">
                    <div class="ai-icon" data-model="gemini-2.5-flash" onclick="window.toggleAI(this)">Gmn</div>
                    <div class="ai-icon" data-model="gpt-4o" onclick="window.toggleAI(this)">GPT</div>
                    <div class="ai-icon" data-model="grok-3" onclick="window.toggleAI(this)">Grk</div>
                    <div class="ai-icon" data-model="claude-3-opus" onclick="window.toggleAI(this)">Cld</div>
                </div>
            </div>

            <div class="messages-area" id="messages-container">
                <div style="text-align: center; color: var(--text-muted); margin-top: 50px;">
                    یک گفتگو را انتخاب کنید یا مستقیماً با هوش مصنوعی چت کنید.
                </div>
            </div>
            <div class="typing-indicator" id="typing-status">در حال نوشتن...</div>

            <div class="chat-input-area">
                <div class="toolbar">
                    <input type="file" id="file-upload" style="display: none;" onchange="window.handleRealFileSelect(event)">
                    <span title="آپلود فایل" onclick="window.triggerFileUpload()">📎</span>
                    <span id="upload-spinner">در حال آپلود...</span>
                </div>
                <input type="text" id="msg-input" placeholder="پیام خود را بنویسید... (Enter برای ارسال)" onkeypress="window.handleEnter(event)">
                <button class="btn-send" onclick="window.sendMessage()">➤</button>
            </div>
        </div>
    </div>

    <script>
        window.currentUser = localStorage.getItem('chat_username') || '';
        window.currentFullName = localStorage.getItem('chat_fullname') || '';
        window.activeChatUser = null; 
        window.openTabs = [];
        window.activeAIModels = [];
        window.lastMessageCount = 0;
        window.engineInterval = null;

        window.handleAuthEnter = function(e) { if (e.key === 'Enter') window.authenticate(); };

        window.authenticate = async function() {
            const user = document.getElementById('login-user').value.trim();
            const name = document.getElementById('login-name').value.trim();
            const pass = document.getElementById('login-pass').value;
            const msgBox = document.getElementById('auth-msg');

            if(!user || !pass) { msgBox.innerText = "پر کردن نام کاربری و رمز عبور الزامیست!"; return; }

            try {
                const res = await fetch('?api=auth', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({username: user, fullname: name, password: pass})
                });
                const data = await res.json();

                if (data.status === 'success') {
                    localStorage.setItem('chat_username', user);
                    localStorage.setItem('chat_fullname', data.fullname);
                    window.currentUser = user;
                    window.currentFullName = data.fullname;
                    document.getElementById('auth-screen').style.display = 'none';
                    document.getElementById('chat-app').style.display = 'flex';
                    window.startEngine();
                } else { msgBox.innerText = data.message; }
            } catch (err) { msgBox.innerText = "خطا در ارتباط با سرور!"; }
        };

        let searchTimeout = null;
        window.handleAutocomplete = function(query) {
            const dropdown = document.getElementById('autocomplete-list');
            if (query.trim().length === 0) { dropdown.style.display = 'none'; return; }

            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(async () => {
                const res = await fetch('?api=autocomplete', {
                    method: 'POST', body: JSON.stringify({query: query, me: window.currentUser})
                });
                const data = await res.json();
                
                if (data.status === 'success' && data.users.length > 0) {
                    dropdown.innerHTML = '';
                    data.users.forEach(u => {
                        dropdown.innerHTML += `
                            <div class="autocomplete-item" onclick="window.selectAutocompleteUser('${u.username}', '${u.fullname}')">
                                <b>${u.fullname}</b> <span style="color:var(--text-muted); font-size:11px;">(${u.username})</span>
                            </div>`;
                    });
                    dropdown.style.display = 'block';
                } else {
                    dropdown.innerHTML = '<div class="autocomplete-item" style="color:var(--text-muted); cursor:default;">کاربری یافت نشد...</div>';
                    dropdown.style.display = 'block';
                }
            }, 300); 
        };

        window.selectAutocompleteUser = function(username, fullname) {
            document.getElementById('search-input').value = '';
            document.getElementById('autocomplete-list').style.display = 'none';
            window.openChatTab(username, fullname);
        };

        document.addEventListener('click', function(e) {
            if(!e.target.closest('.search-container')) {
                document.getElementById('autocomplete-list').style.display = 'none';
            }
        });

        window.openChatTab = function(username, fullname) {
            if (!window.openTabs.find(t => t.username === username)) window.openTabs.push({username, fullname});
            window.activeChatUser = username;
            window.renderTabs();
            window.loadMessages(true);
        };

        window.closeTab = function(event, username) {
            event.stopPropagation();
            window.openTabs = window.openTabs.filter(t => t.username !== username);
            if (window.activeChatUser === username) window.activeChatUser = window.openTabs.length > 0 ? window.openTabs[0].username : null;
            window.renderTabs();
            if(window.activeChatUser) window.loadMessages(true); else window.clearActiveChat();
        };

        window.clearActiveChat = function() {
            window.activeChatUser = null;
            window.renderTabs();
            document.getElementById('messages-container').innerHTML = '<div style="text-align: center; color: var(--text-muted); margin-top: 50px;">یک گفتگو را انتخاب کنید یا مستقیماً با هوش مصنوعی چت کنید.</div>';
        };

        window.renderTabs = function() {
            const container = document.getElementById('tabs-container');
            container.innerHTML = `<div class="tab ${!window.activeChatUser ? 'active' : ''}" onclick="window.clearActiveChat()">پنل اصلی</div>`;
            window.openTabs.forEach(t => {
                const isActive = window.activeChatUser === t.username ? 'active' : '';
                const isAI = t.username === 'ai_assistant' ? 'ai-tab' : '';
                container.innerHTML += `
                    <div class="tab ${isActive} ${isAI}" onclick="window.openChatTab('${t.username}', '${t.fullname}')">
                        ${t.fullname} <span class="close-tab" onclick="window.closeTab(event, '${t.username}')">✖</span>
                    </div>`;
            });
        };

        // ==========================================
        // مکانیزم آپلود واقعی فایل به سرور
        // ==========================================
        window.triggerFileUpload = function() {
            if (!window.activeChatUser) { alert("رئیس! اول یه تب چت باز کن تا بتونی فایل بفرستی."); return; }
            document.getElementById('file-upload').click();
        };

        window.handleRealFileSelect = async function(event) {
            const file = event.target.files[0];
            if (!file) return;

            const spinner = document.getElementById('upload-spinner');
            spinner.style.display = 'inline-block';

            const formData = new FormData();
            formData.append('file', file);

            try {
                const response = await fetch('?api=upload_file', {
                    method: 'POST',
                    body: formData // چون FormData هست، مرورگر اتوماتیک هدر Multipart رو تنظیم میکنه
                });
                
                const data = await response.json();
                spinner.style.display = 'none';

                if (data.status === 'success') {
                    // تولید HTML هوشمند بر اساس نوع فایل
                    let fileAttachmentHTML = '';
                    if (data.is_image) {
                        fileAttachmentHTML = `<br><a href="${data.file_url}" target="_blank"><img src="${data.file_url}" class="chat-image" alt="${data.file_name}"></a>`;
                    } else {
                        fileAttachmentHTML = `<br><a href="${data.file_url}" class="chat-file-link" target="_blank" download="${data.file_name}">📥 دانلود: ${data.file_name}</a>`;
                    }

                    // ارسال خودکار فایل به عنوان یک پیام در چت!
                    await fetch('?api=send_message', {
                        method: 'POST',
                        body: JSON.stringify({
                            sender: window.currentUser,
                            receiver: window.activeChatUser,
                            text: `[پیوست ارسال شد] ${fileAttachmentHTML}`,
                            ai_models: window.activeAIModels
                        })
                    });
                    
                    window.loadMessages(true);
                } else {
                    alert('خطا در آپلود: ' + data.message);
                }
            } catch (error) {
                spinner.style.display = 'none';
                alert('ارتباط با سرور برای آپلود قطع شد!');
            }
            
            event.target.value = ''; // ریست کردن برای آپلود مجدد همان فایل
        };

        // ==========================================
        // سیستم پیام‌رسان
        // ==========================================
        window.handleEnter = function(e) { if (e.key === 'Enter') window.sendMessage(); };

        window.sendMessage = async function() {
            if (!window.activeChatUser) { alert("رئیس! اول یه تب چت باز کن."); return; }
            const input = document.getElementById('msg-input');
            const text = input.value.trim();
            if (!text) return;

            input.value = '';
            
            // جلوگیری از رندر خام کدهای HTML تایپ شده توسط کاربر برای امنیت (XSS)
            let safeText = text.replace(/</g, "&lt;").replace(/>/g, "&gt;");
            window.appendMessage({sender: window.currentUser, text: safeText, timestamp: Date.now() / 1000});

            if(window.activeAIModels.length > 0 || window.activeChatUser === 'ai_assistant') {
                document.getElementById('typing-status').style.display = 'block';
                document.getElementById('typing-status').innerText = 'دستیار در حال پردازش...';
            }

            await fetch('?api=send_message', {
                method: 'POST',
                body: JSON.stringify({
                    sender: window.currentUser,
                    receiver: window.activeChatUser,
                    text: safeText,
                    ai_models: window.activeAIModels
                })
            });
            
            document.getElementById('typing-status').style.display = 'none';
            window.loadMessages(true);
        };

        window.loadMessages = async function(forceScroll = false) {
            if (!window.activeChatUser) return;
            const res = await fetch('?api=get_messages', {
                method: 'POST', body: JSON.stringify({user1: window.currentUser, user2: window.activeChatUser})
            });
            const data = await res.json();
            
            if (data.status === 'success' && data.messages.length !== window.lastMessageCount) {
                window.lastMessageCount = data.messages.length;
                const container = document.getElementById('messages-container');
                container.innerHTML = '';
                
                data.messages.forEach(msg => window.appendMessage(msg));
                if (forceScroll) container.scrollTop = container.scrollHeight;
            }
        };

        window.appendMessage = function(msg) {
            const container = document.getElementById('messages-container');
            const isMe = msg.sender === window.currentUser;
            const isAI = msg.sender.startsWith('AI:');
            let cssClass = isMe ? 'msg-me' : (isAI ? 'msg-ai' : 'msg-other');
            
            const date = new Date(msg.timestamp * 1000);
            const timeStr = date.getHours() + ":" + date.getMinutes().toString().padStart(2, '0');
            let senderTag = (!isMe) ? `<div class="msg-sender">${msg.sender}</div>` : '';

            container.innerHTML += `
                <div class="message ${cssClass}">
                    ${senderTag}
                    ${msg.text} 
                    <span class="msg-time">${timeStr}</span>
                </div>
            `;
            container.scrollTop = container.scrollHeight;
        };

        window.toggleAI = function(element) {
            const model = element.getAttribute('data-model');
            element.classList.toggle('active');
            if (element.classList.contains('active')) window.activeAIModels.push(model);
            else window.activeAIModels = window.activeAIModels.filter(m => m !== model);
        };

        window.engineTick = async function() {
            try {
                const res = await fetch('?api=ping', {
                    method: 'POST', body: JSON.stringify({username: window.currentUser})
                });
                const data = await res.json();
                
                if (data.status === 'success') {
                    const list = document.getElementById('contacts-list');
                    list.innerHTML = '';
                    data.contacts.forEach(c => {
                        const statusClass = c.online ? 'online' : 'offline';
                        list.innerHTML += `
                            <div class="contact-item" onclick="window.openChatTab('${c.username}', '${c.fullname}')">
                                <div class="status-dot ${statusClass}"></div>
                                <div class="contact-name">${c.fullname}</div>
                            </div>
                        `;
                    });
                }
                if (window.activeChatUser) window.loadMessages(false);
            } catch (err) { console.error("Engine Network Error: ", err); }
        };

        window.startEngine = function() {
            if (window.engineInterval) clearInterval(window.engineInterval);
            window.engineTick();
            window.engineInterval = setInterval(window.engineTick, 3000); 
        };

        window.onload = function() {
            if (window.currentUser) {
                document.getElementById('auth-screen').style.display = 'none';
                document.getElementById('chat-app').style.display = 'flex';
                window.startEngine();
            }
        };
    </script>
</body>
</html>
