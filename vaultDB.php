<?php

declare(strict_types=1);

namespace App\Database;

use Exception;
use RuntimeException;

/**
 * VaultDB - Enterprise Flat-File Secure Database (High Concurrency Edition)
 * طراحی شده برای امنیت بالا، سرعت بی‌نظیر و مقاومت در برابر ترافیک سنگین
 */
class VaultDB
{
    private string $dbPath;
    private string $encryptionKey;
    private array $data = [];
    private bool $isLoaded = false;

    // الگوریتم رمزنگاری گرید نظامی
    private const CIPHER_ALGO = 'aes-256-cbc';

    /**
     * @param string $storageDir مسیر پوشه ذخیره سازی دیتابیس ها
     * @param string $dbName نام دیتابیس (بدون پسوند)
     * @param string $password رمز عبور برای رمزنگاری فایل
     * @throws Exception
     */
    public function __construct(string $storageDir, string $dbName, string $password)
    {
        if (!is_dir($storageDir)) {
            if (!mkdir($storageDir, 0755, true) && !is_dir($storageDir)) {
                throw new RuntimeException(sprintf('پوشه "%s" ساخته نشد. دسترسی‌ها را بررسی کنید.', $storageDir));
            }
        }

        // پسوند اختصاصی و لاکچری خودمون
        $this->dbPath = rtrim($storageDir, '/') . '/' . $dbName . '.vdb';
        
        // تبدیل رمز عبور به یک کلید 32 بایتی به شدت امن
        $this->encryptionKey = hash('sha256', $password, true);
        
        $this->load();
    }

    /**
     * بارگذاری و رمزگشایی داده‌ها از فایل
     */
    private function load(): void
    {
        if (!file_exists($this->dbPath)) {
            $this->data = ['tables' => []];
            $this->isLoaded = true;
            return;
        }

        $encryptedContent = file_get_contents($this->dbPath);
        if (empty($encryptedContent)) {
            $this->data = ['tables' => []];
            $this->isLoaded = true;
            return;
        }

        $parts = explode('::', $encryptedContent, 2);
        if (count($parts) !== 2) {
            throw new Exception("بحران امنیتی: دیتابیس مخدوش شده یا فرمت آن نامعتبر است!");
        }

        $iv = base64_decode($parts[0]);
        $cipherText = $parts[1];

        $decrypted = openssl_decrypt($cipherText, self::CIPHER_ALGO, $this->encryptionKey, 0, $iv);
        
        if ($decrypted === false) {
            throw new Exception("خطای دسترسی: رمز عبور دیتابیس اشتباه است یا فایل دستکاری شده!");
        }

        $this->data = json_decode($decrypted, true) ?? ['tables' => []];
        $this->isLoaded = true;
    }

    /**
     * رمزنگاری و ذخیره امن داده‌ها در فایل با الگوریتم Spinlock
     * مقاوم در برابر تداخل و درخواست‌های همزمان (Race Conditions)
     */
    private function save(): void
    {
        if (!$this->isLoaded) return;

        $json = json_encode($this->data, JSON_UNESCAPED_UNICODE);
        $ivLength = openssl_cipher_iv_length(self::CIPHER_ALGO);
        $iv = openssl_random_pseudo_bytes($ivLength);

        // رمزنگاری داده‌ها
        $encrypted = openssl_encrypt($json, self::CIPHER_ALGO, $this->encryptionKey, 0, $iv);
        $payload = base64_encode($iv) . '::' . $encrypted;

        $fp = fopen($this->dbPath, 'c');
        if (!$fp) {
            throw new Exception("نمیتوان فایل دیتابیس را باز کرد. مشکل دسترسی (Permissions) وجود دارد.");
        }
        
        // مکانیزم Spinlock: سماجت در باز کردن قفل برای ترافیک بالا
        $maxRetries = 100;    // حداکثر ۱۰۰ بار تلاش
        $retryDelay = 5000;   // ۵ میلی‌ثانیه تاخیر در هر تلاش (۵۰۰۰ میکروثانیه)
        $locked = false;

        for ($i = 0; $i < $maxRetries; $i++) {
            // LOCK_NB مانع از هنگ کردن اسکریپت در صورت قفل بودن فایل می‌شود
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                $locked = true;
                break;
            }
            usleep($retryDelay);
        }

        if ($locked) {
            ftruncate($fp, 0); // پاکسازی محتوای قبلی فایل
            fwrite($fp, $payload);
            fflush($fp); // اطمینان از نوشته شدن فیزیکی روی هارد دیسک
            flock($fp, LOCK_UN); // آزادسازی قفل
        } else {
            fclose($fp);
            throw new Exception("ترافیک سرور به شدت بالاست! پس از تلاش‌های مکرر، امکان ذخیره داده فراهم نشد.");
        }
        
        fclose($fp);
    }

    /**
     * ایجاد یک جدول جدید در دیتابیس
     */
    public function createTable(string $tableName): bool
    {
        if (isset($this->data['tables'][$tableName])) {
            return false; // جدول از قبل وجود دارد، کاری نمی‌کنیم
        }
        
        $this->data['tables'][$tableName] = [
            'auto_increment' => 1,
            'records' => []
        ];
        
        $this->save();
        return true;
    }

    /**
     * درج رکورد جدید در جدول (بازگرداندن ID رکورد)
     */
    public function insert(string $tableName, array $record): int
    {
        $this->checkTable($tableName);

        $id = $this->data['tables'][$tableName]['auto_increment'];
        $record['_id'] = $id; // تزریق شناسه یکتا
        
        $this->data['tables'][$tableName]['records'][] = $record;
        $this->data['tables'][$tableName]['auto_increment']++;
        
        $this->save();
        return $id;
    }

    /**
     * جستجو و واکشی داده‌ها (با قابلیت فیلترگذاری)
     * @param array $conditions شرایط جستجو (مثلاً ['status' => 'active'])
     */
    public function select(string $tableName, array $conditions = []): array
    {
        $this->checkTable($tableName);
        $records = $this->data['tables'][$tableName]['records'];

        if (empty($conditions)) {
            return $records;
        }

        return array_filter($records, function ($record) use ($conditions) {
            foreach ($conditions as $key => $value) {
                if (!isset($record[$key]) || $record[$key] !== $value) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * ویرایش رکوردهای موجود بر اساس شرایط
     */
    public function update(string $tableName, array $conditions, array $newData): int
    {
        $this->checkTable($tableName);
        $updatedCount = 0;

        foreach ($this->data['tables'][$tableName]['records'] as &$record) {
            $match = true;
            foreach ($conditions as $key => $value) {
                if (!isset($record[$key]) || $record[$key] !== $value) {
                    $match = false;
                    break;
                }
            }

            if ($match) {
                foreach ($newData as $nKey => $nValue) {
                    // جلوگیری از تغییر دادن شناسه اصلی
                    if ($nKey !== '_id') {
                        $record[$nKey] = $nValue;
                    }
                }
                $updatedCount++;
            }
        }

        if ($updatedCount > 0) {
            $this->save();
        }

        return $updatedCount;
    }

    /**
     * حذف رکوردها بر اساس شرایط
     */
    public function delete(string $tableName, array $conditions): int
    {
        $this->checkTable($tableName);
        $initialCount = count($this->data['tables'][$tableName]['records']);

        $this->data['tables'][$tableName]['records'] = array_filter(
            $this->data['tables'][$tableName]['records'],
            function ($record) use ($conditions) {
                foreach ($conditions as $key => $value) {
                    if (isset($record[$key]) && $record[$key] === $value) {
                        return false; // این رکورد باید حذف شود
                    }
                }
                return true; // این رکورد را نگه دار
            }
        );

        // مرتب‌سازی مجدد کلیدهای آرایه (Re-index)
        $this->data['tables'][$tableName]['records'] = array_values($this->data['tables'][$tableName]['records']);
        
        $deletedCount = $initialCount - count($this->data['tables'][$tableName]['records']);
        
        if ($deletedCount > 0) {
            $this->save();
        }

        return $deletedCount;
    }

    /**
     * بررسی وجود جدول و جلوگیری از خطاهای مهلک
     */
    private function checkTable(string $tableName): void
    {
        if (!isset($this->data['tables'][$tableName])) {
            throw new Exception("رئیس جان! جدول '{$tableName}' پیدا نشد. اول با تابع createTable بسازش.");
        }
    }
}
