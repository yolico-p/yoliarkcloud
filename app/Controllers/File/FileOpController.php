<?php

namespace App\Controllers\File;

use App\Controllers\BaseController;
use App\Core\Security;

class FileOpController extends BaseController
{
    public function createFolder()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $parentId = intval($this->input('parent_id', 0));
        $folderName = $this->input('folder_name', '');

        if (empty($folderName)) {
            $this->error('文件夹名称不能为空');
        }

        $result = $this->fileManager()->createFolder($parentId, $folderName);
        Security::jsonOutput($result);
    }

    public function delete()
    {
        $this->requireAuth();
        $this->validateCSRF();
        $this->rateLimit('delete', 20, 60);

        $fileId = intval($this->input('file_id', 0));

        $result = $this->fileManager()->deleteFile($fileId);
        Security::jsonOutput($result);
    }

    public function batchDelete()
    {
        $this->requireAuth();
        $this->validateCSRF();
        $this->rateLimit('batch_delete', 10, 120);

        $fileIds = $this->parseIdList('file_ids');
        if (empty($fileIds)) {
            $this->error('请选择要删除的文件');
        }

        $successCount = 0;
        $failCount = 0;
        $lastError = '';

        foreach ($fileIds as $id) {
            $result = $this->fileManager()->deleteFile($id);
            if ($result['success']) {
                $successCount++;
            } else {
                $failCount++;
                $lastError = $result['message'] ?? '删除失败';
            }
        }

        if ($failCount === 0) {
            Security::jsonOutput(['success' => true, 'message' => "批量删除完成：{$successCount} 个成功"]);
        } else if ($successCount > 0) {
            Security::jsonOutput(['success' => true, 'message' => "批量删除完成：{$successCount} 个成功，{$failCount} 个失败"]);
        } else {
            Security::jsonOutput(['success' => false, 'message' => '批量删除失败：' . $lastError]);
        }
    }

    public function rename()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $fileId = intval($this->input('file_id', 0));
        $newName = $this->input('new_name', '');

        if (empty($newName)) {
            $this->error('新名称不能为空');
        }

        $result = $this->fileManager()->renameFile($fileId, $newName);

        Security::jsonOutput($result);
    }

    public function move()
    {
        $this->requireAuth();
        $this->validateCSRF();
        $this->rateLimit('move', 15, 60);

        $fileId = intval($this->input('file_id', 0));
        $targetParentId = intval($this->input('target_parent_id', 0));

        $result = $this->fileManager()->moveFile($fileId, $targetParentId);

        Security::jsonOutput($result);
    }

    public function copy()
    {
        $this->requireAuth();
        $this->validateCSRF();
        $this->rateLimit('copy', 10, 60);

        $fileId = intval($this->input('file_id', 0));
        $targetParentId = intval($this->input('target_parent_id', 0));

        $result = $this->fileManager()->copyFile($fileId, $targetParentId);

        Security::jsonOutput($result);
    }

    public function toggleFavorite()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $fileId = intval($this->input('file_id', 0));

        $result = $this->favoriteService()->toggleFavorite($fileId);

        Security::jsonOutput($result);
    }

    public function toggleLock()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $fileId = intval($this->input('file_id', 0));

        $file = $this->fileManager()->getFileById($fileId);
        if (!$file) {
            $this->error('文件不存在');
        }

        $newStatus = $file['is_locked'] ? 0 : 1;
        $this->db->update('files', [
            'is_locked' => $newStatus,
            'updated_at' => time(),
        ], 'id = ? AND user_id = ?', [$fileId, $this->getUserId()]);
        $this->db->invalidateTableCache("files");

        Security::jsonOutput(['success' => true, 'message' => '操作成功', 'is_locked' => $newStatus]);
    }

    public function updateSortOrder()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $orders = json_decode($this->input('orders', '[]'), true);
        if (empty($orders) || !is_array($orders)) {
            $this->error('参数错误');
        }

        foreach ($orders as $item) {
            $fileId = intval($item['id'] ?? 0);
            $sortOrder = intval($item['sort_order'] ?? 0);
            if ($fileId > 0) {
                $this->db->query(
                    'UPDATE files SET sort_order = ? WHERE id = ? AND user_id = ?',
                    [$sortOrder, $fileId, $this->getUserId()]
                );
            }
        }

        Security::jsonOutput(['success' => true, 'message' => '排序已更新']);
    }

    public function batchRename()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $fileIds = $this->input('file_ids', []);
        if (!is_array($fileIds)) {
            $fileIds = json_decode($fileIds, true) ?: [];
        }
        
        $mode = $this->input('mode', '');
        $prefix = $this->input('prefix', '');
        $suffix = $this->input('suffix', '');
        $startNum = intval($this->input('start_num', 1));
        $padLength = intval($this->input('pad_length', 0));
        $find = $this->input('find', '');
        $replace = $this->input('replace', '');
        $keepExt = $this->input('keep_ext', true);

        if (empty($fileIds) || !is_array($fileIds)) {
            $this->error('请选择文件');
        }

        if (empty($mode)) {
            $this->error('请选择重命名方式');
        }

        $userId = $this->getUserId();
        $renamedCount = 0;
        $failCount = 0;
        $errors = [];

        foreach ($fileIds as $index => $fileId) {
            $fileId = intval($fileId);
            $file = $this->fileManager()->getFileById($fileId);
            
            if (!$file) {
                $failCount++;
                $errors[] = "文件不存在";
                continue;
            }
            
            if ($file['is_locked']) {
                $failCount++;
                $errors[] = "{$file['filename']} 已锁定，无法重命名";
                continue;
            }

            $oldName = $file['filename'];
            $ext = pathinfo($oldName, PATHINFO_EXTENSION);
            $nameWithoutExt = pathinfo($oldName, PATHINFO_FILENAME);
            $extWithDot = $ext ? '.' . $ext : '';

            $newName = $oldName;

            switch ($mode) {
                case 'prefix':
                    if (empty($prefix)) {
                        $failCount++;
                        $errors[] = "前缀不能为空";
                        continue 2;
                    }
                    $newName = $prefix . $nameWithoutExt . $extWithDot;
                    break;
                    
                case 'suffix':
                    if (empty($suffix)) {
                        $failCount++;
                        $errors[] = "后缀不能为空";
                        continue 2;
                    }
                    $newName = $nameWithoutExt . $suffix . $extWithDot;
                    break;
                    
                case 'number':
                    $num = $startNum + $index;
                    $numStr = $padLength > 0 ? str_pad($num, $padLength, '0', STR_PAD_LEFT) : (string)$num;
                    $newName = $keepExt ? ($numStr . $extWithDot) : $numStr;
                    break;
                    
                case 'replace':
                    if (empty($find)) {
                        $failCount++;
                        $errors[] = "查找内容不能为空";
                        continue 2;
                    }
                    $newName = str_replace($find, $replace, $oldName);
                    break;
                    
                case 'prefix_suffix':
                    if (empty($prefix) && empty($suffix)) {
                        $failCount++;
                        $errors[] = "前缀和后缀至少填写一项";
                        continue 2;
                    }
                    $newName = $prefix . $nameWithoutExt . $suffix . $extWithDot;
                    break;
            }

            if ($newName !== $oldName) {
                $result = $this->fileManager()->renameFile($fileId, $newName);
                if ($result['success']) {
                    $renamedCount++;
                } else {
                    $failCount++;
                    $errors[] = "{$oldName}: {$result['message']}";
                }
            }
        }

        $totalCount = count($fileIds);
        $successCount = $renamedCount;
        
        if ($failCount === 0) {
            $this->success("批量重命名完成：{$successCount} 个成功");
        } else if ($successCount > 0) {
            $this->success("批量重命名完成：{$successCount} 个成功，{$failCount} 个失败", [
                'renamed' => $renamedCount,
                'failed' => $failCount,
                'errors' => array_slice($errors, 0, 5)
            ]);
        } else {
            $this->error('批量重命名失败：' . implode('；', array_slice($errors, 0, 3)));
        }
    }

    public function batchMove()
    {
        $this->requireAuth();
        $this->validateCSRF();
        $this->rateLimit('batch_move', 10, 120);

        $fileIds = $this->parseIdList('file_ids');
        $targetParentId = intval($this->input('target_parent_id', 0));

        if (empty($fileIds)) {
            $this->error('请选择文件');
        }

        $result = $this->fileManager()->batchMoveItems($fileIds, $targetParentId);
        Security::jsonOutput($result);
    }

    public function batchCopy()
    {
        $this->requireAuth();
        $this->validateCSRF();
        $this->rateLimit('batch_copy', 8, 120);

        $fileIds = $this->parseIdList('file_ids');
        $targetParentId = intval($this->input('target_parent_id', 0));

        if (empty($fileIds)) {
            $this->error('请选择文件');
        }

        $result = $this->fileManager()->batchCopyItems($fileIds, $targetParentId);
        Security::jsonOutput($result);
    }

    public function updateDescription()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $fileId = intval($this->input('file_id', 0));
        $description = $this->input('description', '');

        $result = $this->fileManager()->updateDescription($fileId, $description);

        Security::jsonOutput($result);
    }

    public function updateTags()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $fileId = intval($this->input('file_id', 0));
        $tags = $this->input('tags', []);
        if (is_string($tags)) {
            $tags = array_filter(array_map('trim', explode(',', $tags)));
        }
        if (!is_array($tags)) {
            $tags = [];
        }

        $result = $this->fileManager()->updateTags($fileId, $tags);

        Security::jsonOutput($result);
    }

    public function toggleEncryption()
    {
        $this->requireAuth();
        $this->validateCSRF();

        $fileId = intval($this->input('file_id', 0));

        $file = $this->queryService()->getFileById($fileId);
        if (!$file) {
            $this->error('文件不存在');
        }

        if (!empty($file['is_encrypted'])) {
            $result = $this->encryptionService()->decryptFile($fileId);
        } else {
            $result = $this->encryptionService()->encryptFile($fileId);
        }

        Security::jsonOutput($result);
    }
}
