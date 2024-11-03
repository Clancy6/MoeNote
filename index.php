<?php
// index.php
require_once 'settings.php';

class PasteBoard {
    private $db;
    private $settings;
    
    public function __construct() {
        $this->settings = new Settings();
        $this->db = new SQLite3($this->settings->getSetting('db_path'));
    }
    
    public function getPaste($uuid) {
        $stmt = $this->db->prepare('SELECT * FROM notes WHERE uuid = :uuid');
        $stmt->bindValue(':uuid', $uuid, SQLITE3_TEXT);
        $result = $stmt->execute();
        
        if ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // 检查是否过期
            if (time() > $row['expires_at']) {
                $this->deletePaste($uuid);
                return null;
            }
            
            // 检查访问次数
            if ($row['max_views'] > 0 && $row['current_views'] >= $row['max_views']) {
                $this->deletePaste($uuid);
                return null;
            }
            
            // 更新访问次数
            $stmt = $this->db->prepare('UPDATE notes SET current_views = current_views + 1 WHERE uuid = :uuid');
            $stmt->bindValue(':uuid', $uuid, SQLITE3_TEXT);
            $stmt->execute();
            
            return $row;
        }
        return null;
    }
    
    private function deletePaste($uuid) {
        $stmt = $this->db->prepare('DELETE FROM notes WHERE uuid = :uuid');
        $stmt->bindValue(':uuid', $uuid, SQLITE3_TEXT);
        $stmt->execute();
    }
}

$pasteboard = new PasteBoard();
$settings = new Settings();
$paste = null;
$uuid = isset($_GET['id']) ? $_GET['id'] : null;

if ($uuid) {
    $paste = $pasteboard->getPaste($uuid);
    if (!$paste) {
        header("HTTP/1.0 404 Not Found");
        echo "Paste not found or expired";
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($settings->getSetting('site_name')); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars($settings->getSetting('favicon_path')); ?>">
    <link rel="stylesheet" href="css/marked.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="container">
        <h1><?php echo htmlspecialchars($settings->getSetting('site_name')); ?></h1>
        <p><?php echo htmlspecialchars($settings->getSetting('site_description')); ?></p>
        
        <?php if ($paste): ?>
            <div class="info">
                Views: <?php echo $paste['current_views']; ?>/<?php echo $paste['max_views'] ? $paste['max_views'] : '∞'; ?>
                | Expires: <?php echo date('Y-m-d H:i:s', $paste['expires_at']); ?>
            </div>
            <div id="content" <?php echo $paste['is_encrypted'] ? 'style="display:none;"' : ''; ?>>
                <?php if ($paste['is_markdown']): ?>
                    <div id="markdown-content"></div>
                <?php else: ?>
                    <pre><?php echo htmlspecialchars($paste['content']); ?></pre>
                <?php endif; ?>
            </div>
            <?php if ($paste['is_encrypted']): ?>
                <div id="decrypt-section">
                    <p>This content is encrypted. The decryption key should be in the URL after the #.</p>
                    <div id="decryption-error" style="color: red; display: none;">
                        Failed to decrypt content. Please check the decryption key.
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <form id="pasteForm">
                <textarea name="content" placeholder="Paste your content here..."></textarea>
                <div class="options">
                    <div class="option">
                        <label>Expire after:</label>
                        <select name="expire_time">
                            <option value="3600">1 hour</option>
                            <option value="86400">1 day</option>
                            <option value="604800">1 week</option>
                            <option value="2592000" selected>1 month</option>
                            <option value="31536000">1 year</option>
                        </select>
                    </div>
                    <div class="option">
                        <label>Max views (0 for unlimited):</label>
                        <input type="number" name="max_views" value="0" min="0" max="25565">
                    </div>
                    <div class="option">
                        <label>
                            <input type="checkbox" name="is_markdown"> Enable Markdown
                        </label>
                    </div>
                    <div class="option">
                        <label>
                            <input type="checkbox" name="is_encrypted"> Enable encryption
                        </label>
                    </div>
                </div>
                <button type="submit">Create Note</button>
            </form>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/crypto-js/4.1.1/crypto-js.min.js"></script>
    <script>
        <?php if ($paste && $paste['is_markdown']): ?>
        document.getElementById('markdown-content').innerHTML = marked.parse(<?php echo json_encode($paste['content']); ?>);
        <?php endif; ?>

        <?php if ($paste && $paste['is_encrypted']): ?>
        const encryptedContent = <?php echo json_encode($paste['content']); ?>;
        const decryptionKey = window.location.hash.substring(1);
        
        if (decryptionKey) {
            try {
                const decryptedBytes = CryptoJS.AES.decrypt(encryptedContent, decryptionKey);
                const decryptedContent = decryptedBytes.toString(CryptoJS.enc.Utf8);
                
                if (decryptedContent) {
                    const content = document.getElementById('content');
                    content.style.display = 'block';
                    if (<?php echo $paste['is_markdown'] ? 'true' : 'false'; ?>) {
                        content.innerHTML = marked.parse(decryptedContent);
                    } else {
                        content.innerHTML = `<pre>${decryptedContent}</pre>`;
                    }
                    document.getElementById('decrypt-section').style.display = 'none';
                } else {
                    document.getElementById('decryption-error').style.display = 'block';
                }
            } catch (e) {
                document.getElementById('decryption-error').style.display = 'block';
            }
        }
        <?php endif; ?>

        if (document.getElementById('pasteForm')) {
            document.getElementById('pasteForm').onsubmit = async (e) => {
                e.preventDefault();
                const formData = new FormData(e.target);
                const data = Object.fromEntries(formData.entries());
                
                if (data.is_encrypted) {
                    const key = CryptoJS.lib.WordArray.random(32).toString();
                    data.content = CryptoJS.AES.encrypt(data.content, key).toString();
                    data.encryption_key = key;
                }
                
                try {
                    const response = await fetch('api.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(data)
                    });
                    
                    const result = await response.json();
                    if (result.status === 'success') {
                        let url = `${window.location.origin}/${result.uuid}`; // let url = `${window.location.origin}${window.location.pathname}?id=${result.uuid}`;
                        if (data.is_encrypted) {
                            url += '#' + result.encryption_key;
                        }
                        window.location.href = url;
                    } else {
                        alert('Error creating paste: ' + result.message);
                    }
                } catch (error) {
                    alert('Error creating paste: ' + error.message);
                }
            };
        }
    </script>
</body>
</html>
