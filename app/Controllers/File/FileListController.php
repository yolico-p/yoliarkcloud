<?php

namespace App\Controllers\File;

use App\Controllers\BaseController;
use App\Core\Security;
use App\Core\Config;

class FileListController extends BaseController
{
    public function listFiles()
    {
        $this->requireAuth();

        $parentId = intval($this->input('parent_id', 0));
        $sortBy = $this->input('sort_by', 'name');
        $sortOrder = $this->input('sort_order', 'asc');
        $page = intval($this->input('page', 1));
        $pageSize = intval($this->input('page_size', 100));

        $files = $this->fileManager()->listFiles($parentId, $sortBy, $sortOrder, $page, $pageSize);

        Security::jsonOutput(['success' => true, 'files' => $files]);
    }

    public function getFavorites()
    {
        $this->requireAuth();

        $page = intval($this->input('page', 1));
        $files = $this->favoriteService()->getFavorites($page);

        Security::jsonOutput(['success' => true, 'files' => $files]);
    }

    public function search()
    {
        $this->requireAuth();
        $this->rateLimit('search', 30, 60);

        $keyword = $this->input('keyword', '');
        $type = $this->input('type', 'all');
        $page = intval($this->input('page', 1));
        $sortBy = $this->input('sort_by', 'name');
        $sortOrder = $this->input('sort_order', 'asc');

        if (empty($keyword)) {
            Security::jsonOutput(['success' => false, 'message' => '搜索关键词不能为空']);
        }

        $files = $this->queryService()->searchFiles($keyword, $type, $page, 50, $sortBy, $sortOrder);

        Security::jsonOutput(['success' => true, 'files' => $files]);
    }

    public function fileInfo()
    {
        $this->requireAuth();

        $fileId = intval($this->input('file_id', 0));
        $file = $this->queryService()->getFileById($fileId);

        if (!$file) {
            Security::jsonOutput(['success' => false, 'message' => '文件不存在']);
        }

        Security::jsonOutput(['success' => true, 'file' => $file]);
    }

    public function breadcrumb()
    {
        $this->requireAuth();

        $parentId = intval($this->input('parent_id', 0));
        $breadcrumb = $this->queryService()->getBreadcrumb($parentId);

        Security::jsonOutput(['success' => true, 'breadcrumb' => $breadcrumb]);
    }

    public function storageInfo()
    {
        $this->requireAuth();

        $info = $this->queryService()->getStorageInfo();

        Security::jsonOutput(['success' => true, 'storage' => $info]);
    }

    public function fileStats()
    {
        $this->requireAuth();

        $stats = $this->queryService()->getFileStats();

        Security::jsonOutput(['success' => true, 'stats' => $stats]);
    }

    public function listAllFolders()
    {
        $this->requireAuth();

        $folders = $this->fileManager()->getAllFoldersTree();

        Security::jsonOutput(['success' => true, 'folders' => $folders]);
    }

    public function recentAccess()
    {
        $this->requireAuth();

        $items = $this->accessService()->getRecentAccess();

        Security::jsonOutput(['success' => true, 'items' => $items]);
    }
}
