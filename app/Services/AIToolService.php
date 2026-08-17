<?php

namespace App\Services;

use App\Core\Database;
use App\Core\Security;

class AIToolService
{
    private $db;
    private $apiKey;
    private $baseUrl;

    public function __construct()
    {
        $this->db = Database::getInstance();
        // Load AI config for sub-agent spawning
        $configFile = DATA_PATH . DIRECTORY_SEPARATOR . 'ai_agent.json';
        if (file_exists($configFile)) {
            $data = json_decode(file_get_contents($configFile), true);
            if (is_array($data)) {
                $this->apiKey = $data['api_key'] ?? '';
                $this->baseUrl = $data['base_url'] ?? 'https://open.bigmodel.cn/api/paas/v4';
            }
        }
    }

    public function getToolDefinitions()
    {
        return [
            // ===== 浏览类 =====
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_files',
                    'description' => '列出目录下的文件/文件夹，可指定排序方式。可指定parent_id浏览子目录，depth控制递归深度。默认仅当前目录，按名称升序。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'parent_id' => ['type' => 'integer', 'description' => '目录ID，根目录为0，默认0。用户说"当前目录"时用上下文中的dir_id'],
                            'page' => ['type' => 'integer', 'description' => '第几页，从1开始，默认1'],
                            'depth' => ['type' => 'integer', 'description' => '递归深度：1=仅当前目录（默认），2=含子目录，3=含孙目录'],
                            'sort_by' => ['type' => 'string', 'description' => '排序字段：name（名称，默认）/size（大小）/created（创建时间）/updated（修改时间）'],
                            'sort_order' => ['type' => 'string', 'description' => '排序方向：asc（升序，默认）/desc（降序）'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_files',
                    'description' => '按文件名关键词搜索文件。type可过滤文件类型。注意：本工具只搜文件名，如需搜索文件内容请用search_content。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'keyword' => ['type' => 'string', 'description' => '搜索关键词，匹配文件名。为空时配合type可列出所有该类型文件'],
                            'type' => ['type' => 'string', 'description' => '过滤类型: all/folder/image/video/audio/document/archive，默认all'],
                            'page' => ['type' => 'integer', 'description' => '第几页，从1开始，默认1'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_content',
                    'description' => '搜索文件内容（支持文本、PDF、Office文档）。返回包含关键词的文件列表及匹配片段。用于"哪个文件里提到了XXX"类需求。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'keyword' => ['type' => 'string', 'description' => '搜索关键词'],
                            'parent_id' => ['type' => 'integer', 'description' => '搜索范围目录ID，根目录为0，默认0（全局搜索）'],
                            'max_results' => ['type' => 'integer', 'description' => '最大返回结果数，默认20'],
                        ],
                        'required' => ['keyword'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'read_file',
                    'description' => '读取文件内容（文本/PDF/Office格式提取文本）。用于分析文档、总结内容。不支持图片/视频/音频等二进制文件。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'file_id' => ['type' => 'integer', 'description' => '文件ID'],
                            'max_length' => ['type' => 'integer', 'description' => '最大返回字符数，默认5000，最大50000'],
                        ],
                        'required' => ['file_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_file_info',
                    'description' => '获取文件/文件夹的详细信息：大小、类型、创建时间、修改时间、收藏状态、分享状态等。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'file_id' => ['type' => 'integer', 'description' => '文件ID'],
                        ],
                        'required' => ['file_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_recent_files',
                    'description' => '列出最近修改的文件，按修改时间降序。可用于"最近编辑的文件""这周改过的文档"等需求。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'days' => ['type' => 'integer', 'description' => '最近N天的文件，默认7'],
                            'type' => ['type' => 'string', 'description' => '过滤类型: all/image/video/audio/document/archive，默认all'],
                            'limit' => ['type' => 'integer', 'description' => '返回数量，默认20'],
                        ],
                    ],
                ],
            ],

            // ===== 操作类 =====
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_folder',
                    'description' => '创建文件夹。支持一次创建多个（folder_names传数组）。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'parent_id' => ['type' => 'integer', 'description' => '父目录ID，根目录为0'],
                            'folder_name' => ['type' => 'string', 'description' => '文件夹名称（单个）'],
                            'folder_names' => [
                                'type' => 'array',
                                'description' => '文件夹名称列表（批量创建时使用，优先于folder_name）',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'move_files',
                    'description' => '移动文件/文件夹到目标目录（原位置文件消失）。支持单个(file_id)或批量(file_ids)。批量移动2个以上时系统会自动弹出确认卡片。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'file_id' => ['type' => 'integer', 'description' => '单个文件ID'],
                            'file_ids' => [
                                'type' => 'array',
                                'description' => '文件ID列表（批量移动时使用，优先于file_id）',
                                'items' => ['type' => 'integer'],
                            ],
                            'target_parent_id' => ['type' => 'integer', 'description' => '目标目录ID，根目录为0'],
                        ],
                        'required' => ['target_parent_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'copy_files',
                    'description' => '复制文件/文件夹到目标目录（原位置文件保留）。支持单个(file_id)或批量(file_ids)。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'file_id' => ['type' => 'integer', 'description' => '单个文件ID'],
                            'file_ids' => [
                                'type' => 'array',
                                'description' => '文件ID列表（批量复制时使用，优先于file_id）',
                                'items' => ['type' => 'integer'],
                            ],
                            'target_parent_id' => ['type' => 'integer', 'description' => '目标目录ID，根目录为0'],
                        ],
                        'required' => ['target_parent_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'delete_files',
                    'description' => '删除文件/文件夹（移入回收站，6天后自动清理）。支持单个(file_id)或批量(file_ids)。批量删除2个以上时系统会自动弹出确认卡片，无需在文本中要求用户确认。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'file_id' => ['type' => 'integer', 'description' => '单个文件ID'],
                            'file_ids' => [
                                'type' => 'array',
                                'description' => '文件ID列表（批量删除时使用，优先于file_id）',
                                'items' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'rename_file',
                    'description' => '重命名单个文件或文件夹。如需批量重命名多个文件请用batch_rename。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'file_id' => ['type' => 'integer', 'description' => '文件ID'],
                            'new_name' => ['type' => 'string', 'description' => '新名称'],
                        ],
                        'required' => ['file_id', 'new_name'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'batch_rename',
                    'description' => '批量重命名多个文件。支持用模式串统一命名：{seq}替换为序号，{name}保留原文件名（不含扩展名），{ext}保留扩展名。例如pattern="旅行_{seq}" + start=1 → 旅行_1.jpg, 旅行_2.png...',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'file_ids' => [
                                'type' => 'array',
                                'description' => '要重命名的文件ID列表',
                                'items' => ['type' => 'integer'],
                            ],
                            'pattern' => ['type' => 'string', 'description' => '命名模式，支持占位符：{seq}=序号, {name}=原名(不含扩展名), {ext}=扩展名'],
                            'start' => ['type' => 'integer', 'description' => '序号起始值，默认1'],
                            'padding' => ['type' => 'integer', 'description' => '序号补零位数，如padding=3则001/002/003，默认不补零'],
                        ],
                        'required' => ['file_ids', 'pattern'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'toggle_favorite',
                    'description' => '收藏或取消收藏文件。action为add添加收藏，remove取消收藏，toggle切换状态。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'file_id' => ['type' => 'integer', 'description' => '文件ID'],
                            'action' => ['type' => 'string', 'description' => '操作：add（收藏）/remove（取消）/toggle（切换，默认）'],
                        ],
                        'required' => ['file_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'navigate_to',
                    'description' => '让前端页面跳转到指定文件夹或打开文件预览。用于"带我去这个文件夹""打开这个文件"类需求。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'file_id' => ['type' => 'integer', 'description' => '目标文件/文件夹ID。如果是文件夹则跳转到该目录，如果是文件则打开预览'],
                        ],
                        'required' => ['file_id'],
                    ],
                ],
            ],

            // ===== 分享类 =====
            [
                'type' => 'function',
                'function' => [
                    'name' => 'create_share',
                    'description' => '为文件创建分享链接，自动生成二维码。file_id从搜索/列表工具结果获取。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'file_id' => ['type' => 'integer', 'description' => '文件ID'],
                            'password' => ['type' => 'string', 'description' => '提取密码，留空则无密码'],
                            'expire_days' => ['type' => 'integer', 'description' => '有效期天数，0为永久'],
                        ],
                        'required' => ['file_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_shares',
                    'description' => '查看已创建的分享链接列表。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'page' => ['type' => 'integer', 'description' => '第几页，默认1'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'delete_share',
                    'description' => '撤销分享链接。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'share_id' => ['type' => 'integer', 'description' => '分享记录ID'],
                        ],
                        'required' => ['share_id'],
                    ],
                ],
            ],

            // ===== 分析类 =====
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_storage_info',
                    'description' => '获取存储空间使用情况：总容量、已用、剩余、各类型文件统计、最大文件列表。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'detail' => ['type' => 'boolean', 'description' => '是否返回详细分类统计，默认false'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'find_duplicates',
                    'description' => '查找重复文件。优先用内容哈希(content_hash)精确判断内容相同，无哈希时回退到同名+同大小。返回重复文件分组列表，不执行删除。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'parent_id' => ['type' => 'integer', 'description' => '扫描目录，根目录为0，默认0（扫描全部）'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'find_large_files',
                    'description' => '查找大文件，按大小降序返回。type可指定文件类型（如image/video），默认全部。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'parent_id' => ['type' => 'integer', 'description' => '查找目录，根目录为0，默认0'],
                            'type' => ['type' => 'string', 'description' => '文件类型: all/image/video/audio/document/archive，默认all'],
                            'limit' => ['type' => 'integer', 'description' => '返回数量，默认10'],
                        ],
                    ],
                ],
            ],

            // ===== 回收站 =====
            [
                'type' => 'function',
                'function' => [
                    'name' => 'list_trash',
                    'description' => '查看回收站文件列表。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'page' => ['type' => 'integer', 'description' => '第几页，默认1'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'restore_files',
                    'description' => '从回收站恢复文件到原位置。支持单个(file_id)或批量(file_ids)。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'file_id' => ['type' => 'integer', 'description' => '单个文件ID'],
                            'file_ids' => [
                                'type' => 'array',
                                'description' => '文件ID列表（批量恢复时使用，优先于file_id）',
                                'items' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'empty_trash',
                    'description' => '清空回收站，永久删除所有回收站文件。此操作不可撤销，系统会自动弹出确认卡片。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [],
                    ],
                ],
            ],

            // ===== 复合工具 =====
            [
                'type' => 'function',
                'function' => [
                    'name' => 'organize_files_by_type',
                    'description' => '按文件类型自动整理目录：自动创建图片/文档/视频/音频/压缩包等分类文件夹，并将文件移动到对应文件夹。一步完成整理，无需手动 list→create_folder→move。示例：用户说"按类型整理这个文件夹"时直接调用此工具。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'parent_id' => ['type' => 'integer', 'description' => '要整理的目录ID，根目录为0'],
                            'auto_confirm' => ['type' => 'boolean', 'description' => '是否自动执行（无需确认），默认false返回计划供用户确认'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_and_delete',
                    'description' => '搜索并删除匹配文件（一步完成）。根据关键词搜索文件并移入回收站。默认会先返回匹配列表等待确认，设置auto_confirm=true则直接删除。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'keyword' => ['type' => 'string', 'description' => '搜索关键词，匹配文件名'],
                            'type' => ['type' => 'string', 'description' => '过滤类型: all/folder/image/video/audio/document/archive，默认all'],
                            'auto_confirm' => ['type' => 'boolean', 'description' => '是否自动执行删除（无需确认），默认false'],
                        ],
                        'required' => ['keyword'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_and_move',
                    'description' => '搜索并移动匹配文件到目标目录（一步完成）。根据关键词搜索文件并移动到指定目录。默认会先返回匹配列表等待确认，设置auto_confirm=true则直接移动。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'keyword' => ['type' => 'string', 'description' => '搜索关键词，匹配文件名'],
                            'type' => ['type' => 'string', 'description' => '过滤类型: all/folder/image/video/audio/document/archive，默认all'],
                            'target_parent_id' => ['type' => 'integer', 'description' => '目标目录ID，根目录为0'],
                            'auto_confirm' => ['type' => 'boolean', 'description' => '是否自动执行移动（无需确认），默认false'],
                        ],
                        'required' => ['keyword', 'target_parent_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'cleanup_empty_folders',
                    'description' => '扫描目录下的空文件夹并返回列表（不直接删除，需配合delete_files使用）。用于"清理空文件夹"需求的第一步。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'parent_id' => ['type' => 'integer', 'description' => '要扫描的目录ID，根目录为0'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_file_tree',
                    'description' => '获取目录树结构（递归展示文件夹和文件层级）。用于"看看这个文件夹的结构""目录里有什么"等需要层级视图的需求。带30秒缓存。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'parent_id' => ['type' => 'integer', 'description' => '起始目录ID，根目录为0'],
                            'max_depth' => ['type' => 'integer', 'description' => '最大递归深度，默认3'],
                            'max_nodes' => ['type' => 'integer', 'description' => '最大节点数，默认500，防止超时'],
                            'include_files' => ['type' => 'boolean', 'description' => '是否包含文件（仅文件夹时设false），默认true'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'scan_files',
                    'description' => '扫描目录概览：快速统计文件夹/文件数量、总大小，并返回样本列表。比list_files更精简，适合"这个目录大概有什么"的概览需求。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'parent_id' => ['type' => 'integer', 'description' => '要扫描的目录ID，根目录为0'],
                            'type_filter' => ['type' => 'string', 'description' => '过滤类型: all/folder/file，默认all'],
                            'sample_count' => ['type' => 'integer', 'description' => '返回样本数量，默认20'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_file_stats_by_type',
                    'description' => '按文件类型统计目录内文件的数量、大小、占比。用于"看看各类文件占用情况""哪些类型文件最多"等分析需求。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'parent_id' => ['type' => 'integer', 'description' => '统计目录ID，根目录为0，默认0'],
                        ],
                    ],
                ],
            ],

            // ===== 增强工具 =====
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_folder_size',
                    'description' => '计算文件夹总大小（递归包含所有子文件）。用于"这个文件夹多大""文件夹占多少空间"等需求。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'folder_id' => ['type' => 'integer', 'description' => '文件夹ID'],
                        ],
                        'required' => ['folder_id'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'find_and_share_largest_image',
                    'description' => '查找目录下最大的图片文件并自动创建分享链接（一步完成）。用于"把最大的图片分享给我"等组合需求。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'parent_id' => ['type' => 'integer', 'description' => '查找目录ID，根目录为0，默认0'],
                            'password' => ['type' => 'string', 'description' => '提取密码，留空则无密码'],
                            'expire_days' => ['type' => 'integer', 'description' => '有效期天数，0为永久'],
                        ],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'search_and_share',
                    'description' => '搜索文件并自动创建分享链接（一步完成）。若匹配多个文件会返回列表等待用户指定。用于"把xxx文件分享出去"等组合需求。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'keyword' => ['type' => 'string', 'description' => '搜索关键词，匹配文件名'],
                            'type' => ['type' => 'string', 'description' => '过滤类型: all/folder/image/video/audio/document/archive，默认all'],
                            'password' => ['type' => 'string', 'description' => '提取密码，留空则无密码'],
                            'expire_days' => ['type' => 'integer', 'description' => '有效期天数，0为永久'],
                        ],
                        'required' => ['keyword'],
                    ],
                ],
            ],

            // ===== 智能体协作 =====
            [
                'type' => 'function',
                'function' => [
                    'name' => 'manage_todo',
                    'description' => '管理任务清单（TODO）。多步任务请先用此工具创建任务清单，每步完成打勾，避免遗忘。支持操作：add(添加任务)、complete(标记完成)、update(更新内容/状态)、list(查看清单)、clear(清空)。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'description' => '操作：add/complete/update/list/clear'],
                            'session_id' => ['type' => 'string', 'description' => '会话ID（可从上下文获取，通常无需手动指定）'],
                            'items' => [
                                'type' => 'array',
                                'description' => 'add 操作时的任务列表，每项为 {content: "任务描述"}',
                                'items' => ['type' => 'object'],
                            ],
                            'content' => ['type' => 'string', 'description' => '单个任务内容（add/update 时使用）'],
                            'id' => ['type' => 'integer', 'description' => '任务ID（complete/update 时使用）'],
                            'status' => ['type' => 'string', 'description' => '状态：pending(进行中)/completed(已完成)'],
                        ],
                        'required' => ['action'],
                    ],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'spawn_subagent',
                    'description' => '派发独立子任务给子 agent 执行。适用于可并行处理的独立子任务（如"整理图片"和"整理文档"可同时派发）。子 agent 有独立上下文，不影响主对话。注意：子 agent 不可再派发子 agent。',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'task' => ['type' => 'string', 'description' => '子任务描述，要清晰具体'],
                            'context' => ['type' => 'object', 'description' => '子任务所需上下文，如 {file_ids: [1,2,3], parent_id: 5}'],
                            'tools' => [
                                'type' => 'array',
                                'description' => '子 agent 可用工具白名单（可选，默认全部除 spawn_subagent 外）',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                        'required' => ['task'],
                    ],
                ],
            ],
        ];
    }

    public function executeTool($name, $args)
    {
        $userId = $_SESSION['user_id'] ?? 0;
        if (!$userId) {
            return ['error' => '未登录'];
        }

        // 危险操作频率限制
        $dangerousOps = ['delete_files', 'move_files', 'delete_share', 'empty_trash'];
        if (in_array($name, $dangerousOps)) {
            $now = time();
            $lockFile = DATA_PATH . '/.tool_lock_' . md5("{$userId}_{$name}");
            if (file_exists($lockFile)) {
                $lastRun = intval(file_get_contents($lockFile));
                if ($now - $lastRun < 2) {
                    return ['error' => '操作过于频繁，请等待2秒'];
                }
            }
            file_put_contents($lockFile, $now, LOCK_EX);
        }

        // ── 确认机制：批量危险操作需要前端确认 ──
        $needConfirm = false;
        $confirmPreview = null;

        if ($name === 'delete_files' || $name === 'move_files') {
            $fileIds = $args['file_ids'] ?? ($args['file_id'] ? [$args['file_id']] : []);
            if (is_array($fileIds) && count($fileIds) >= 2) {
                // 检查是否已确认
                $confirmed = $args['confirmed'] ?? false;
                if (!$confirmed) {
                    $needConfirm = true;
                    $confirmPreview = $this->previewDangerousOp($name, $fileIds, $args);
                }
            }
        }

        // empty_trash 始终需要确认
        if ($name === 'empty_trash') {
            $confirmed = $args['confirmed'] ?? false;
            if (!$confirmed) {
                $needConfirm = true;
                $trashCount = $this->db->fetch("SELECT COUNT(*) as cnt FROM files WHERE user_id = ? AND deleted_at IS NOT NULL", [$userId])['cnt'] ?? 0;
                $confirmPreview = [
                    'action' => '清空回收站',
                    'file_count' => intval($trashCount),
                    'total_size' => '',
                    'file_samples' => [],
                    'target' => '',
                ];
            }
        }

        if ($needConfirm) {
            return [
                'need_confirm' => true,
                'tool' => $name,
                'args' => $args,
                'preview' => $confirmPreview,
            ];
        }

        $result = null;
        try {
            switch ($name) {
                case 'list_files':
                    $result = $this->toolListFiles($userId, $args);
                    break;
                case 'search_files':
                    $result = $this->toolSearchFiles($userId, $args);
                    break;
                case 'search_content':
                    $result = $this->toolSearchFileContent($userId, $args);
                    break;
                case 'read_file':
                    $result = $this->toolPreviewFile($userId, $args);
                    break;
                case 'get_file_info':
                    $result = $this->toolGetFileInfo($userId, $args);
                    break;
                case 'list_recent_files':
                    $result = $this->toolListRecentFiles($userId, $args);
                    break;
                case 'create_folder':
                    $result = $this->toolCreateFolderUnified($userId, $args);
                    break;
                case 'move_files':
                    $result = $this->toolMoveFilesUnified($userId, $args);
                    break;
                case 'copy_files':
                    $result = $this->toolCopyFiles($userId, $args);
                    break;
                case 'delete_files':
                    $result = $this->toolDeleteFilesUnified($userId, $args);
                    break;
                case 'rename_file':
                    $result = $this->toolRenameFile($userId, $args);
                    break;
                case 'batch_rename':
                    $result = $this->toolBatchRename($userId, $args);
                    break;
                case 'toggle_favorite':
                    $result = $this->toolToggleFavorite($userId, $args);
                    break;
                case 'navigate_to':
                    $result = $this->toolNavigateTo($userId, $args);
                    break;
                case 'create_share':
                    $result = $this->toolCreateShare($userId, $args);
                    break;
                case 'list_shares':
                    $result = $this->toolListShares($userId, $args);
                    break;
                case 'delete_share':
                    $result = $this->toolDeleteShare($userId, $args);
                    break;
                case 'get_storage_info':
                    $result = $this->toolGetStorageInfoUnified($userId, $args);
                    break;
                case 'find_duplicates':
                    $result = $this->toolDetectDuplicates($userId, $args);
                    break;
                case 'find_large_files':
                    $result = $this->toolFindLargeFilesUnified($userId, $args);
                    break;
                case 'list_trash':
                    $result = $this->toolListTrash($userId, $args);
                    break;
                case 'restore_files':
                    $result = $this->toolRestoreFromTrashUnified($userId, $args);
                    break;
                case 'empty_trash':
                    $result = $this->toolEmptyTrash($userId, $args);
                    break;
                // ===== 复合工具 =====
                case 'organize_files_by_type':
                    $result = $this->toolOrganizeFilesByType($userId, $args);
                    break;
                case 'search_and_delete':
                    $result = $this->toolSearchAndDelete($userId, $args);
                    break;
                case 'search_and_move':
                    $result = $this->toolSearchAndMove($userId, $args);
                    break;
                case 'cleanup_empty_folders':
                    $result = $this->toolCleanupEmptyFolders($userId, $args);
                    break;
                case 'get_file_tree':
                    $result = $this->toolGetFileTree($userId, $args);
                    break;
                case 'scan_files':
                    $result = $this->toolScanFiles($userId, $args);
                    break;
                case 'get_file_stats_by_type':
                    $result = $this->toolGetFileStatsByType($userId, $args);
                    break;
                // ===== 增强工具 =====
                case 'get_folder_size':
                    $result = $this->toolGetFolderSize($userId, $args);
                    break;
                case 'find_and_share_largest_image':
                    $result = $this->toolFindAndShareLargestImage($userId, $args);
                    break;
                case 'search_and_share':
                    $result = $this->toolSearchAndShare($userId, $args);
                    break;
                // ===== 智能体协作 =====
                case 'manage_todo':
                    $result = $this->toolManageTodo($userId, $args);
                    break;
                case 'spawn_subagent':
                    $result = $this->toolSpawnSubagent($userId, $args);
                    break;
                default:
                    $result = ['error' => '未知工具：' . $name];
            }
        } catch (\Exception $e) {
            $result = ['error' => $e->getMessage()];
        }

        // Classify error type for intelligent retry
        if (isset($result['error']) && !empty($result['error'])) {
            $errorMsg = is_string($result['error']) ? $result['error'] : '';
            $result['error_type'] = $this->classifyError($errorMsg);
        }

        $this->logOperation($userId, $name, $args, $result);

        return $result;
    }

    public function executeToolWithProgress($name, $args, $progressCallback)
    {
        $progressTools = ['find_duplicates', 'find_large_files', 'get_storage_info', 'list_files'];

        $startTime = microtime(true);

        if (in_array($name, $progressTools)) {
            $progressCallback(10, '正在准备...');
        }

        $result = $this->executeTool($name, $args);

        $elapsed = microtime(true) - $startTime;

        if (in_array($name, $progressTools) && $elapsed > 0.5) {
            $progressCallback(100, '处理完成 (' . round($elapsed, 1) . 's)');
        }

        return $result;
    }

    private function logOperation($userId, $name, $args, $result)
    {
        $logKey = 'ai_operations_' . $userId;
        $logs = $_SESSION[$logKey] ?? [];

        $typeMap = [
            'list_files' => 'search',
            'search_files' => 'search',
            'search_content' => 'search',
            'read_file' => 'search',
            'get_file_info' => 'search',
            'list_recent_files' => 'search',
            'delete_files' => 'delete',
            'move_files' => 'move',
            'copy_files' => 'copy',
            'create_share' => 'share',
            'delete_share' => 'share',
            'create_folder' => 'create',
            'rename_file' => 'create',
            'batch_rename' => 'create',
            'toggle_favorite' => 'favorite',
            'navigate_to' => 'navigate',
            'find_duplicates' => 'search',
            'find_large_files' => 'search',
            'get_storage_info' => 'search',
            'restore_files' => 'restore',
            'empty_trash' => 'delete',
        ];
        $opType = $typeMap[$name] ?? 'other';

        $summary = '';
        if (isset($result['message'])) {
            $summary = $result['message'];
        } elseif (isset($result['error'])) {
            $summary = '失败: ' . $result['error'];
        }

        $logs[] = [
            'time' => time(),
            'tool' => $name,
            'type' => $opType,
            'args' => $args,
            'summary' => $summary,
        ];

        if (count($logs) > 50) {
            $logs = array_slice($logs, -50);
        }

        $_SESSION[$logKey] = $logs;
    }

    private function previewDangerousOp($toolName, $fileIds, $args)
    {
        $userId = $_SESSION['user_id'] ?? 0;
        $fm = new FileManagerService();

        $files = [];
        foreach ($fileIds as $id) {
            $file = $this->db->fetch("SELECT id, filename, filesize, is_dir FROM files WHERE id = ? AND user_id = ?", [$id, $userId]);
            if ($file) {
                $files[] = $file;
            }
        }

        $totalSize = array_sum(array_column($files, 'filesize'));

        $action = $toolName === 'delete_files' ? '删除' : '移动';
        $target = '';
        if ($toolName === 'move_files' && isset($args['target_parent_id'])) {
            $targetDir = $this->db->fetch("SELECT filename FROM files WHERE id = ? AND user_id = ?", [$args['target_parent_id'], $userId]);
            $target = $targetDir ? $targetDir['filename'] : '根目录';
        }

        return [
            'action' => $action,
            'file_count' => count($files),
            'total_size' => \App\Core\Security::formatSize($totalSize),
            'file_samples' => array_slice(array_map(function($f) {
                return [
                    'id' => $f['id'],
                    'name' => $f['filename'],
                    'size' => \App\Core\Security::formatSize($f['filesize']),
                    'is_dir' => $f['is_dir'],
                ];
            }, $files), 0, 10),
            'target' => $target,
        ];
    }

    // ── 统一工具实现 ──

    private function toolCreateFolderUnified($userId, $args)
    {
        $folderNames = $args['folder_names'] ?? null;
        $parentId = intval($args['parent_id'] ?? 0);

        if (!empty($folderNames) && is_array($folderNames)) {
            $fm = new FileManagerService();
            $created = [];
            $errors = [];
            foreach ($folderNames as $name) {
                $name = trim($name);
                if (empty($name)) continue;
                $result = $fm->createFolder($parentId, $name, $userId);
                if (isset($result['error'])) {
                    $errors[] = $name . ': ' . $result['error'];
                } else {
                    $created[] = $name;
                }
            }
            return [
                'success' => count($created) > 0,
                'created_count' => count($created),
                'created' => $created,
                'errors' => $errors,
                'message' => count($created) > 0
                    ? '已创建 ' . count($created) . ' 个文件夹' . (!empty($errors) ? '，' . count($errors) . ' 个失败' : '')
                    : '创建失败',
            ];
        }

        return $this->toolCreateFolder($userId, $args);
    }

    private function toolMoveFilesUnified($userId, $args)
    {
        $fileIds = $args['file_ids'] ?? ($args['file_id'] ? [$args['file_id']] : []);
        $targetParentId = intval($args['target_parent_id'] ?? 0);

        if (empty($fileIds)) {
            return ['error' => '未指定要移动的文件'];
        }

        $fm = new FileManagerService();
        $moved = 0;
        $errors = [];

        foreach ($fileIds as $id) {
            $result = $fm->moveFile(intval($id), $targetParentId, $userId);
            if (isset($result['error'])) {
                $errors[] = $result['error'];
            } else {
                $moved++;
            }
        }

        return [
            'success' => $moved > 0,
            'moved_count' => $moved,
            'total_count' => count($fileIds),
            'errors' => $errors,
            'message' => $moved > 0
                ? "已移动 {$moved} 个文件" . (!empty($errors) ? '，' . count($errors) . ' 个失败' : '')
                : '移动失败',
        ];
    }

    private function toolDeleteFilesUnified($userId, $args)
    {
        $fileIds = $args['file_ids'] ?? ($args['file_id'] ? [$args['file_id']] : []);

        if (empty($fileIds)) {
            return ['error' => '未指定要删除的文件'];
        }

        $fm = new FileManagerService();
        $deleted = 0;
        $errors = [];

        foreach ($fileIds as $id) {
            $result = $fm->deleteFile(intval($id), $userId);
            if (isset($result['error'])) {
                $errors[] = $result['error'];
            } else {
                $deleted++;
            }
        }

        return [
            'success' => $deleted > 0,
            'deleted_count' => $deleted,
            'total_count' => count($fileIds),
            'errors' => $errors,
            'message' => $deleted > 0
                ? "已删除 {$deleted} 个文件到回收站" . (!empty($errors) ? '，' . count($errors) . ' 个失败' : '')
                : '删除失败',
        ];
    }

    private function toolGetStorageInfoUnified($userId, $args)
    {
        $detail = $args['detail'] ?? false;
        $result = $this->toolStorageInfo($userId);

        if ($detail) {
            $stats = $this->toolGetFileStatsByType($userId, []);
            $result['type_stats'] = $stats['type_stats'] ?? [];
            $largeFiles = $this->toolGetLargestImages($userId, ['limit' => 5]);
            $result['largest_files'] = $largeFiles['files'] ?? [];
        }

        return $result;
    }

    private function toolFindLargeFilesUnified($userId, $args)
    {
        $type = $args['type'] ?? 'all';
        $limit = intval($args['limit'] ?? 10);
        $parentId = intval($args['parent_id'] ?? 0);

        if ($type === 'image') {
            return $this->toolGetLargestImages($userId, ['parent_id' => $parentId, 'limit' => $limit]);
        }

        // 通用大文件查找
        $fm = new FileManagerService();
        $allFiles = $fm->listFiles($parentId, 'size', 'desc', 1, 10000);

        if ($type !== 'all') {
            $allFiles = array_filter($allFiles, function($f) use ($type) {
                return !$f['is_dir'] && $f['file_type'] === $type;
            });
        } else {
            $allFiles = array_filter($allFiles, function($f) {
                return !$f['is_dir'];
            });
        }

        $allFiles = array_slice($allFiles, 0, $limit);

        $result = [];
        foreach ($allFiles as $f) {
            $result[] = [
                'id' => $f['id'],
                'name' => $f['filename'],
                'size' => $f['filesize_formatted'],
                'type' => $f['file_type'],
            ];
        }

        return [
            'files' => $result,
            'count' => count($result),
            'message' => count($result) > 0 ? "找到 " . count($result) . " 个大文件" : '未找到文件',
        ];
    }

    private function toolPlanTasks($userId, $args)
    {
        $tasks = $args['tasks'] ?? [];
        $overallRisk = $args['overall_risk'] ?? 'medium';
        $estimatedTime = $args['estimated_time'] ?? '';

        if (empty($tasks)) {
            return ['error' => '请提供执行步骤'];
        }

        $riskLabel = [
            'low' => '低风险',
            'medium' => '中等风险',
            'high' => '高风险',
        ][$overallRisk] ?? '中等风险';

        $confirmSteps = array_filter($tasks, function($t) {
            return $t['need_confirm'] ?? false;
        });

        return [
            'success' => true,
            'plan' => $tasks,
            'overall_risk' => $overallRisk,
            'risk_label' => $riskLabel,
            'estimated_time' => $estimatedTime,
            'confirm_steps_count' => count($confirmSteps),
            'message' => "已制定执行计划（{$riskLabel}）" . ($estimatedTime ? "，预计 {$estimatedTime}" : ""),
            'tip' => count($confirmSteps) > 0
                ? '其中 ' . count($confirmSteps) . ' 个步骤需要您的确认，回复"确认"后我将开始执行'
                : '回复"确认"后我将按步骤执行',
        ];
    }

    private function toolGetRecentOperations($userId, $args)
    {
        $limit = intval($args['limit'] ?? 10);
        $typeFilter = $args['type_filter'] ?? 'all';
        if ($limit <= 0 || $limit > 50) {
            $limit = 10;
        }

        $logKey = 'ai_operations_' . $userId;
        $logs = $_SESSION[$logKey] ?? [];

        // 过滤类型
        if ($typeFilter !== 'all') {
            $logs = array_filter($logs, function($log) use ($typeFilter) {
                return $log['type'] === $typeFilter;
            });
        }

        // 取最近N条，按时间倒序
        $logs = array_slice(array_reverse($logs), 0, $limit);

        // 格式化时间
        $formatted = [];
        foreach ($logs as $log) {
            $formatted[] = [
                'time' => date('Y-m-d H:i:s', $log['time']),
                'tool' => $log['tool'],
                'type' => $log['type'],
                'summary' => $log['summary'],
            ];
        }

        return [
            'success' => true,
            'operations' => $formatted,
            'count' => count($formatted),
            'total_history' => count($_SESSION[$logKey] ?? []),
            'message' => count($formatted) > 0
                ? "最近 " . count($formatted) . " 条操作记录"
                : "暂无操作记录",
        ];
    }

    private function toolListFiles($userId, $args)
    {
        $parentId = intval($args['parent_id'] ?? 0);
        $page = intval($args['page'] ?? 1);
        $pageSize = 100;

        // 支持排序参数
        $sortByMap = [
            'name' => 'name',
            'size' => 'size',
            'created' => 'created_at',
            'updated' => 'updated_at',
        ];
        $sortBy = $sortByMap[$args['sort_by'] ?? 'name'] ?? 'name';
        $sortOrder = ($args['sort_order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $fm = new FileManagerService();
        $files = $fm->listFiles($parentId, $sortBy, $sortOrder, $page, $pageSize);
        $result = [];
        foreach ($files as $f) {
            $item = [
                'id' => $f['id'],
                'name' => $f['filename'],
                'type' => $f['is_dir'] ? 'folder' : $f['file_type'],
                'size' => $f['filesize_formatted'],
                'favorite' => $f['is_favorite'],
            ];
            if (!empty($f['created_at'])) {
                $item['created_at'] = date('Y-m-d H:i', $f['created_at']);
            }
            if (!empty($f['updated_at'])) {
                $item['updated_at'] = date('Y-m-d H:i', $f['updated_at']);
            }
            $result[] = $item;
        }
        $hasMore = count($result) >= $pageSize;
        $fileCount = count($result);
        $result = [
            'files' => $result,
            'count' => $fileCount,
            'page' => $page,
            'has_more' => $hasMore,
            'total_found' => ($page - 1) * $pageSize + $fileCount . ($hasMore ? '+' : ''),
        ];

        // Truncate if >20 items
        if (isset($result['files']) && is_array($result['files']) && count($result['files']) > 20) {
            $totalCount = count($result['files']);
            $result['files'] = array_slice($result['files'], 0, 20);
            $result['truncated'] = true;
            $result['total_count'] = $totalCount;
            $result['truncation_note'] = "仅显示前20项，共{$totalCount}项，请使用 page 参数查看更多";
        }

        // Also strip redundant fields from each file item
        if (isset($result['files']) && is_array($result['files'])) {
            foreach ($result['files'] as &$file) {
                if (is_array($file)) {
                    // Keep only essential fields
                    $essential = [];
                    foreach (['id', 'file_id', 'name', 'filename', 'type', 'is_dir', 'size', 'filesize', 'updated', 'updated_at', 'mime_type'] as $field) {
                        if (isset($file[$field])) {
                            $essential[$field] = $file[$field];
                        }
                    }
                    $file = $essential;
                }
            }
            unset($file);
        }

        return $result;
    }

    private function toolScanFiles($userId, $args)
    {
        $parentId = intval($args['parent_id'] ?? 0);
        $typeFilter = $args['type_filter'] ?? 'all';
        $sampleCount = intval($args['sample_count'] ?? 20);
        $fm = new FileManagerService();

        $allFiles = $fm->listFiles($parentId, 'name', 'asc', 1, 10000);

        $folderCount = 0;
        $fileCount = 0;
        $totalSize = 0;
        $folderNames = [];
        $fileNames = [];

        foreach ($allFiles as $f) {
            if ($f['is_dir']) {
                $folderCount++;
                if (count($folderNames) < $sampleCount) {
                    $folderNames[] = ['id' => $f['id'], 'name' => $f['filename']];
                }
            } else {
                $fileCount++;
                $totalSize += intval($f['filesize'] ?? 0);
                if (count($fileNames) < $sampleCount) {
                    $fileNames[] = ['id' => $f['id'], 'name' => $f['filename'], 'size' => $f['filesize_formatted']];
                }
            }
        }

        $result = [
            'parent_id' => $parentId,
            'total_items' => $folderCount + $fileCount,
            'folder_count' => $folderCount,
            'file_count' => $fileCount,
            'total_size' => \App\Core\Security::formatSize($totalSize),
        ];

        if ($typeFilter === 'all' || $typeFilter === 'folder') {
            $result['folder_samples'] = $folderNames;
            $result['folder_samples_truncated'] = $folderCount > $sampleCount;
        }

        if ($typeFilter === 'all' || $typeFilter === 'file') {
            $result['file_samples'] = $fileNames;
            $result['file_samples_truncated'] = $fileCount > $sampleCount;
        }

        return $result;
    }

    private function toolSearchFiles($userId, $args)
    {
        $keyword = $args['keyword'] ?? '';
        $type = $args['type'] ?? 'all';
        $page = intval($args['page'] ?? 1);
        $pageSize = 50;
        $sortBy = $args['sort_by'] ?? 'name';
        $sortOrder = $args['sort_order'] ?? 'asc';
        $fm = new FileManagerService();
        $files = $fm->searchFiles($keyword, $type, $page, $pageSize, $sortBy, $sortOrder);
        $result = [];
        foreach ($files as $f) {
            $item = [
                'id' => $f['id'],
                'name' => $f['filename'],
                'type' => $f['is_dir'] ? 'folder' : $f['file_type'],
                'size' => $f['filesize_formatted'],
            ];
            // 返回父目录信息，让 AI 能告诉用户文件在哪
            if (!empty($f['parent_id'])) {
                $parentDir = $this->db->fetch("SELECT filename FROM files WHERE id = ?", [$f['parent_id']]);
                $item['parent_folder'] = $parentDir ? $parentDir['filename'] : '根目录';
            }
            if (!empty($f['updated_at'])) {
                $item['updated_at'] = date('Y-m-d H:i', $f['updated_at']);
            }
            $result[] = $item;
        }
        $hasMore = count($result) >= $pageSize;
        $fileCount = count($result);
        $result = [
            'files' => $result,
            'count' => $fileCount,
            'page' => $page,
            'has_more' => $hasMore,
            'total_found' => ($page - 1) * $pageSize + $fileCount . ($hasMore ? '+' : ''),
        ];

        // Truncate if >20 items
        if (isset($result['files']) && is_array($result['files']) && count($result['files']) > 20) {
            $totalCount = count($result['files']);
            $result['files'] = array_slice($result['files'], 0, 20);
            $result['truncated'] = true;
            $result['total_count'] = $totalCount;
            $result['truncation_note'] = "仅显示前20项，共{$totalCount}项";
        }

        // Strip redundant fields
        if (isset($result['files']) && is_array($result['files'])) {
            foreach ($result['files'] as &$file) {
                if (is_array($file)) {
                    $essential = [];
                    foreach (['id', 'file_id', 'name', 'filename', 'type', 'is_dir', 'size', 'filesize', 'updated', 'updated_at'] as $field) {
                        if (isset($file[$field])) {
                            $essential[$field] = $file[$field];
                        }
                    }
                    $file = $essential;
                }
            }
            unset($file);
        }

        return $result;
    }

    private function toolCreateFolder($userId, $args)
    {
        $folderName = $args['folder_name'] ?? '';
        if (empty($folderName)) return ['error' => '请提供文件夹名称'];
        $fm = new FileManagerService();
        return $fm->createFolder(intval($args['parent_id'] ?? 0), $folderName);
    }

    private function toolRenameFile($userId, $args)
    {
        $fileId = intval($args['file_id'] ?? 0);
        $newName = $args['new_name'] ?? '';
        if ($fileId <= 0 || empty($newName)) return ['error' => '参数不完整'];
        $fm = new FileManagerService();
        return $fm->renameFile($fileId, $newName);
    }

    private function toolDeleteFile($userId, $args)
    {
        $fileId = intval($args['file_id'] ?? 0);
        if ($fileId <= 0) return ['error' => '请提供文件 ID'];
        $fm = new FileManagerService();
        return $fm->deleteFile($fileId);
    }

    private function toolDeleteFilesBatch($userId, $args)
    {
        $fileIds = $args['file_ids'] ?? [];
        if (empty($fileIds)) return ['error' => '请提供要删除的文件 ID 列表'];

        $fm = new FileManagerService();
        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($fileIds as $fileId) {
            $fileId = intval($fileId);
            if ($fileId <= 0) {
                $failed++;
                continue;
            }

            $result = $fm->deleteFile($fileId);
            if ($result['success']) {
                $success++;
            } else {
                $failed++;
                $errors[] = "文件 {$fileId} 删除失败" . (isset($result['message']) ? ': ' . $result['message'] : '');
            }
        }

        return [
            'success' => true,
            'deleted' => $success,
            'failed' => $failed,
            'errors' => $errors,
            'message' => "成功删除 {$success} 个文件，失败 {$failed} 个",
        ];
    }

    private function toolMoveFile($userId, $args)
    {
        $fileId = intval($args['file_id'] ?? 0);
        $targetId = intval($args['target_parent_id'] ?? 0);
        if ($fileId <= 0) return ['error' => '请提供文件ID'];
        $fm = new FileManagerService();
        return $fm->moveFile($fileId, $targetId);
    }

    private function toolMoveFilesBatch($userId, $args)
    {
        $fileIds = $args['file_ids'] ?? [];
        $targetId = intval($args['target_parent_id'] ?? 0);
        if (empty($fileIds)) return ['error' => '请提供要移动的文件ID列表'];

        $fm = new FileManagerService();
        $success = 0;
        $failed = 0;
        $errors = [];

        foreach ($fileIds as $fileId) {
            $fileId = intval($fileId);
            if ($fileId <= 0) {
                $failed++;
                continue;
            }
            $result = $fm->moveFile($fileId, $targetId);
            if ($result['success']) {
                $success++;
            } else {
                $failed++;
                $errors[] = "文件 {$fileId} 移动失败" . (isset($result['message']) ? ': ' . $result['message'] : '');
            }
        }

        return [
            'success' => true,
            'moved' => $success,
            'failed' => $failed,
            'target' => $targetId,
            'errors' => $errors,
            'message' => "成功移动 {$success} 个文件，失败 {$failed} 个",
        ];
    }

    private function toolCreateShare($userId, $args)
    {
        $fileId = intval($args['file_id'] ?? 0);
        if ($fileId <= 0) return ['error' => '请提供文件ID'];
        $ss = new ShareService();
        $options = [];
        if (!empty($args['password'])) $options['password'] = $args['password'];
        if (isset($args['expire_days'])) $options['expire_days'] = intval($args['expire_days']);
        return $ss->createShareWithQRCode($fileId, $options);
    }

    private function toolListShares($userId, $args)
    {
        $page = intval($args['page'] ?? 1);
        $pageSize = 20;
        $ss = new ShareService();
        $shares = $ss->listShares($page, $pageSize);
        $result = [];
        foreach ($shares as $s) {
            $result[] = [
                'id' => $s['id'],
                'filename' => $s['filename'] ?? '未知',
                'share_url' => $s['share_url'] ?? '',
                'download_count' => $s['download_count'],
                'has_password' => $s['has_password'],
                'expire_time' => $s['expire_time'] ?? '永久',
            ];
        }
        $hasMore = count($result) >= $pageSize;
        return [
            'shares' => $result,
            'count' => count($result),
            'page' => $page,
            'has_more' => $hasMore,
            'total_found' => ($page - 1) * $pageSize + count($result) . ($hasMore ? '+' : ''),
        ];
    }

    private function toolDeleteShare($userId, $args)
    {
        $shareId = intval($args['share_id'] ?? 0);
        if ($shareId <= 0) return ['error' => '请提供分享记录ID'];
        $ss = new ShareService();
        return $ss->deleteShare($shareId);
    }

    private function toolStorageInfo($userId)
    {
        $fm = new FileManagerService();
        return $fm->getStorageInfo();
    }

    private function toolListTrash($userId, $args)
    {
        $page = intval($args['page'] ?? 1);
        $pageSize = 20;
        $ts = new TrashService();
        $items = $ts->listTrash($page, $pageSize);
        $result = [];
        foreach ($items as $i) {
            $result[] = [
                'id' => $i['id'],
                'name' => $i['filename'],
                'size' => $i['filesize_formatted'],
                'deleted_at' => $i['deleted_at_formatted'],
                'remaining_days' => $i['remaining_days'],
            ];
        }
        $hasMore = count($result) >= $pageSize;
        return [
            'items' => $result,
            'count' => count($result),
            'page' => $page,
            'has_more' => $hasMore,
            'total_found' => ($page - 1) * $pageSize + count($result) . ($hasMore ? '+' : ''),
        ];
    }

    private function toolRestoreFromTrash($userId, $args)
    {
        $fileId = intval($args['file_id'] ?? 0);
        if ($fileId <= 0) return ['error' => '请提供文件ID'];
        $ts = new TrashService();
        return $ts->restore($fileId);
    }

    /**
     * 统一版恢复工具：支持单个(file_id)或批量(file_ids)
     */
    private function toolRestoreFromTrashUnified($userId, $args)
    {
        $fileIds = $args['file_ids'] ?? ($args['file_id'] ? [$args['file_id']] : []);
        if (empty($fileIds) || !is_array($fileIds)) {
            return ['error' => '未指定要恢复的文件'];
        }

        $ts = new TrashService();
        $restored = 0;
        $errors = [];
        foreach ($fileIds as $id) {
            $result = $ts->restore(intval($id));
            if (isset($result['error']) || isset($result['success']) && !$result['success']) {
                $errors[] = 'ID ' . $id . ': ' . ($result['error'] ?? $result['message'] ?? '恢复失败');
            } else {
                $restored++;
            }
        }
        return [
            'success' => $restored > 0,
            'restored_count' => $restored,
            'errors' => $errors,
            'message' => $restored > 0
                ? '已恢复 ' . $restored . ' 个文件' . (!empty($errors) ? '，' . count($errors) . ' 个失败' : '')
                : '恢复失败',
        ];
    }

    /**
     * 清空回收站
     */
    private function toolEmptyTrash($userId, $args)
    {
        $ts = new TrashService();
        $result = $ts->emptyTrash();
        return [
            'success' => $result['success'] ?? true,
            'message' => $result['message'] ?? '回收站已清空',
        ];
    }

    /**
     * 复制文件到目标目录
     */
    private function toolCopyFiles($userId, $args)
    {
        $fileIds = $args['file_ids'] ?? ($args['file_id'] ? [$args['file_id']] : []);
        $targetParentId = intval($args['target_parent_id'] ?? 0);

        if (empty($fileIds) || !is_array($fileIds)) {
            return ['error' => '未指定要复制的文件'];
        }

        $fm = new FileManagerService();
        $copied = 0;
        $errors = [];
        foreach ($fileIds as $id) {
            $result = $fm->copyFile(intval($id), $targetParentId);
            if (isset($result['error']) || (isset($result['success']) && !$result['success'])) {
                $errors[] = 'ID ' . $id . ': ' . ($result['error'] ?? $result['message'] ?? '复制失败');
            } else {
                $copied++;
            }
        }
        return [
            'success' => $copied > 0,
            'copied_count' => $copied,
            'errors' => $errors,
            'message' => $copied > 0
                ? '已复制 ' . $copied . ' 个文件' . (!empty($errors) ? '，' . count($errors) . ' 个失败' : '')
                : '复制失败',
        ];
    }

    /**
     * 批量重命名
     * pattern 支持 {seq} {name} {ext} 占位符
     */
    private function toolBatchRename($userId, $args)
    {
        $fileIds = $args['file_ids'] ?? [];
        $pattern = $args['pattern'] ?? '';
        $start = intval($args['start'] ?? 1);
        $padding = intval($args['padding'] ?? 0);

        if (empty($fileIds) || !is_array($fileIds)) {
            return ['error' => '请提供要重命名的文件ID列表'];
        }
        if (empty($pattern)) {
            return ['error' => '请提供命名模式'];
        }

        $renamed = 0;
        $errors = [];
        $seq = $start;

        foreach ($fileIds as $id) {
            $file = $this->db->fetch("SELECT id, filename, file_type FROM files WHERE id = ? AND user_id = ?", [intval($id), $userId]);
            if (!$file) {
                $errors[] = 'ID ' . $id . ': 文件不存在';
                continue;
            }

            $origName = $file['filename'];
            $ext = '';
            $nameWithoutExt = $origName;
            $dotPos = strrpos($origName, '.');
            if ($dotPos !== false && $dotPos > 0) {
                $ext = substr($origName, $dotPos); // 含点 .jpg
                $nameWithoutExt = substr($origName, 0, $dotPos);
            }

            $seqStr = $padding > 0 ? str_pad($seq, $padding, '0', STR_PAD_LEFT) : strval($seq);

            $newName = $pattern;
            $newName = str_replace('{seq}', $seqStr, $newName);
            $newName = str_replace('{name}', $nameWithoutExt, $newName);
            $newName = str_replace('{ext}', $ext, $newName);

            // 如果模式中没有 {ext} 且原文件有扩展名，自动补上
            if (strpos($pattern, '{ext}') === false && $ext !== '' && strpos($newName, $ext) === false) {
                $newName .= $ext;
            }

            $fm = new FileManagerService();
            $result = $fm->renameFile(intval($id), $newName);
            if (isset($result['error']) || (isset($result['success']) && !$result['success'] && $result['message'] !== '文件名未改变')) {
                $errors[] = $origName . ': ' . ($result['error'] ?? $result['message'] ?? '重命名失败');
            } else {
                $renamed++;
            }
            $seq++;
        }

        return [
            'success' => $renamed > 0,
            'renamed_count' => $renamed,
            'errors' => $errors,
            'message' => $renamed > 0
                ? '已重命名 ' . $renamed . ' 个文件' . (!empty($errors) ? '，' . count($errors) . ' 个失败' : '')
                : '重命名失败',
        ];
    }

    /**
     * 收藏/取消收藏
     */
    private function toolToggleFavorite($userId, $args)
    {
        $fileId = intval($args['file_id'] ?? 0);
        $action = $args['action'] ?? 'toggle';
        if ($fileId <= 0) return ['error' => '请提供文件ID'];

        $file = $this->db->fetch("SELECT id, is_favorite FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);
        if (!$file) return ['error' => '文件不存在或无权限'];

        $currentFav = intval($file['is_favorite'] ?? 0);
        $newFav = 0;
        $actionLabel = '';
        if ($action === 'add') {
            $newFav = 1;
            $actionLabel = '已收藏';
        } elseif ($action === 'remove') {
            $newFav = 0;
            $actionLabel = '已取消收藏';
        } else {
            $newFav = $currentFav ? 0 : 1;
            $actionLabel = $newFav ? '已收藏' : '已取消收藏';
        }

        if ($newFav === $currentFav) {
            return ['success' => true, 'message' => '文件已是' . ($newFav ? '收藏' : '未收藏') . '状态'];
        }

        $this->db->update('files', ['is_favorite' => $newFav], 'id = ? AND user_id = ?', [$fileId, $userId]);
        return ['success' => true, 'message' => $actionLabel];
    }

    /**
     * 获取文件详细信息
     */
    private function toolGetFileInfo($userId, $args)
    {
        $fileId = intval($args['file_id'] ?? 0);
        if ($fileId <= 0) return ['error' => '请提供文件ID'];

        $fm = new FileManagerService();
        $file = $fm->getFileById($fileId);
        if (!$file || $file['user_id'] != $userId) {
            return ['error' => '文件不存在或无权限'];
        }

        $info = [
            'id' => $file['id'],
            'name' => $file['filename'],
            'type' => $file['is_dir'] ? 'folder' : ($file['file_type'] ?? ''),
            'size' => $file['filesize_formatted'] ?? '',
            'is_dir' => (bool)$file['is_dir'],
            'is_favorite' => (bool)($file['is_favorite'] ?? 0),
            'is_encrypted' => (bool)($file['is_encrypted'] ?? 0),
        ];

        if (!empty($file['created_at'])) {
            $info['created_at'] = date('Y-m-d H:i:s', $file['created_at']);
        }
        if (!empty($file['updated_at'])) {
            $info['updated_at'] = date('Y-m-d H:i:s', $file['updated_at']);
        }
        if (!empty($file['parent_id'])) {
            $parent = $this->db->fetch("SELECT filename FROM files WHERE id = ?", [$file['parent_id']]);
            $info['parent_folder'] = $parent ? $parent['filename'] : '根目录';
        }
        // 查询是否有分享
        $share = $this->db->fetch("SELECT id FROM shares WHERE file_id = ? AND user_id = ? AND is_active = 1", [$fileId, $userId]);
        $info['is_shared'] = !empty($share);

        return [
            'success' => true,
            'file_info' => $info,
            'message' => "文件：{$file['filename']}" .
                '（' . ($file['is_dir'] ? '文件夹' : ($file['filesize_formatted'] ?? '')) .
                '，创建于 ' . $info['created_at'] .
                '，修改于 ' . $info['updated_at'] .
                ($info['is_favorite'] ? '，已收藏' : '') .
                ($info['is_shared'] ? '，已分享' : '') .
                '）',
        ];
    }

    /**
     * 让前端跳转到指定文件夹或打开文件预览
     */
    private function toolNavigateTo($userId, $args)
    {
        $fileId = intval($args['file_id'] ?? 0);
        if ($fileId <= 0) return ['error' => '请提供文件ID'];

        $file = $this->db->fetch("SELECT id, filename, is_dir FROM files WHERE id = ? AND user_id = ?", [$fileId, $userId]);
        if (!$file) return ['error' => '文件不存在或无权限'];

        return [
            'success' => true,
            'navigate_to' => $fileId,
            'is_dir' => (bool)$file['is_dir'],
            'file_name' => $file['filename'],
            'message' => $file['is_dir']
                ? "正在跳转到文件夹「{$file['filename']}」"
                : "正在打开文件「{$file['filename']}」",
        ];
    }

    /**
     * 列出最近修改的文件
     */
    private function toolListRecentFiles($userId, $args)
    {
        $days = intval($args['days'] ?? 7);
        if ($days <= 0) $days = 7;
        $type = $args['type'] ?? 'all';
        $limit = intval($args['limit'] ?? 20);
        if ($limit <= 0 || $limit > 100) $limit = 20;

        $cutoff = time() - $days * 86400;

        $typeCondition = '';
        $typeParams = [];
        $typeMap = [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif'],
            'video' => ['mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm', 'm4v', 'rmvb', 'mpg', 'mpeg'],
            'audio' => ['mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'm4a'],
            'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'csv', 'json'],
            'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
        ];

        if ($type !== 'all' && isset($typeMap[$type])) {
            $placeholders = implode(',', array_fill(0, count($typeMap[$type]), '?'));
            $typeCondition = " AND file_type IN ($placeholders)";
            $typeParams = $typeMap[$type];
        }

        $sql = "SELECT id, filename, file_type, filesize, is_dir, created_at, updated_at FROM files WHERE user_id = ? AND deleted_at IS NULL AND updated_at >= ?{$typeCondition} ORDER BY updated_at DESC LIMIT ?";
        $params = array_merge([$userId, $cutoff], $typeParams, [$limit]);
        $files = $this->db->fetchAll($sql, $params);

        $result = [];
        foreach ($files as $f) {
            $result[] = [
                'id' => $f['id'],
                'name' => $f['filename'],
                'type' => $f['is_dir'] ? 'folder' : $f['file_type'],
                'size' => \App\Core\Security::formatSize(intval($f['filesize'])),
                'updated_at' => date('Y-m-d H:i', $f['updated_at']),
            ];
        }

        return [
            'files' => $result,
            'count' => count($result),
            'days' => $days,
            'message' => count($result) > 0
                ? "最近 {$days} 天内修改的 {$type} 文件共 " . count($result) . " 个"
                : "最近 {$days} 天内没有修改的文件",
        ];
    }

    private function toolGenerateQRCode($args)
    {
        $url = $args['url'] ?? '';
        if (empty($url)) return ['error' => '请提供URL'];

        $svg = $this->generateQRCodeSVG($url);
        // 后端无可用 QR 库时，返回空 svg 并提示前端基于 url 自行生成
        return [
            'success' => true,
            'qrcode_svg' => $svg,
            'url' => $url,
            'frontend_generate' => ($svg === ''),
        ];
    }

    private function toolExtractShareLink($args)
    {
        $text = $args['text'] ?? '';
        if (empty($text)) return ['error' => '请提供文本'];

        preg_match_all('/https?:\/\/[^\s<>"\']+/', $text, $matches);
        $links = array_values(array_unique($matches[0] ?? []));
        return ['links' => $links, 'count' => count($links)];
    }

    private function toolCleanupEmptyFolders($userId, $args)
    {
        $parentId = intval($args['parent_id'] ?? 0);

        $fm = new FileManagerService();
        $allFolders = $fm->listFiles($parentId, 'name', 'asc', 1, 10000);

        $emptyFolders = [];
        foreach ($allFolders as $folder) {
            if (!$folder['is_dir']) continue;
            $children = $fm->listFiles($folder['id'], 'name', 'asc', 1, 10000);
            if (empty($children)) {
                $emptyFolders[] = [
                    'id' => $folder['id'],
                    'name' => $folder['filename'],
                    'path' => $folder['filepath'] ?? '/',
                ];
            }
        }

        return [
            'empty_folders' => $emptyFolders,
            'count' => count($emptyFolders),
            'message' => count($emptyFolders) > 0
                ? "发现 " . count($emptyFolders) . " 个空文件夹"
                : "未发现空文件夹，目录很干净！",
            'tip' => '如需清理，请确认后使用 delete_files_batch 工具删除这些空文件夹',
        ];
    }

    private function toolDetectDuplicates($userId, $args)
    {
        $parentId = intval($args['parent_id'] ?? 0);

        $fm = new FileManagerService();
        $allFiles = $fm->listFiles($parentId, 'name', 'asc', 1, 10000);

        // 优先用 content_hash 分组（内容级精确查重），无 hash 时回退到同名+同大小
        $hashGroups = [];
        $fallbackGroups = [];
        foreach ($allFiles as $file) {
            if ($file['is_dir']) continue;

            $fileInfo = [
                'id' => $file['id'],
                'name' => $file['filename'],
                'size' => $file['filesize_formatted'],
                'path' => $file['filepath'] ?? '/',
            ];

            // 优先按 content_hash 分组
            $contentHash = $file['content_hash'] ?? '';
            if (!empty($contentHash)) {
                $hashKey = 'hash_' . $contentHash;
                $hashGroups[$hashKey][] = $fileInfo;
            }

            // 同时按名称+大小分组作为回退
            $fallbackKey = $file['filename'] . '_' . $file['filesize'];
            $fallbackGroups[$fallbackKey][] = $fileInfo;
        }

        // 优先使用 hash 分组结果（更精确），无 hash 时用 fallback
        $useHash = !empty($hashGroups);
        $signatures = $useHash ? $hashGroups : $fallbackGroups;

        $duplicates = [];
        foreach ($signatures as $group) {
            if (count($group) >= 2) {
                $duplicates[] = [
                    'name' => $group[0]['name'],
                    'size' => $group[0]['size'],
                    'occurrences' => count($group),
                    'files' => $group,
                    'match_type' => $useHash ? 'content_hash' : 'name_size',
                ];
            }
        }

        $totalDupFiles = array_sum(array_map(fn($d) => $d['occurrences'], $duplicates));

        return [
            'duplicate_groups' => $duplicates,
            'groups_count' => count($duplicates),
            'total_duplicate_files' => $totalDupFiles,
            'match_type' => $useHash ? 'content_hash' : 'name_size',
            'message' => count($duplicates) > 0
                ? "发现 " . count($duplicates) . " 组重复文件，共涉及 {$totalDupFiles} 个文件" . ($useHash ? "（基于内容哈希精确匹配）" : "（基于同名+同大小，建议确认内容是否真正相同）")
                : "未发现重复文件",
        ];
    }

    private function toolGetLargestImages($userId, $args)
    {
        $parentId = intval($args['parent_id'] ?? 0);
        $limit = intval($args['limit'] ?? 10);

        $fm = new FileManagerService();
        $files = $fm->listFiles($parentId, 'size', 'desc', 1, $limit);

        $images = [];
        foreach ($files as $file) {
            if ($file['is_dir']) continue;
            
            $imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif'];
            if (!in_array(strtolower($file['file_type']), $imageTypes)) continue;

            $images[] = [
                'id' => $file['id'],
                'name' => $file['filename'],
                'size' => $file['filesize_formatted'],
                'filesize' => $file['filesize'],
                'path' => $file['filepath'] ?? '/',
                'type' => $file['file_type'],
            ];
        }

        return [
            'images' => $images,
            'count' => count($images),
            'message' => count($images) > 0
                ? "找到 " . count($images) . " 个图片文件"
                : "该目录下没有找到图片文件",
        ];
    }

    private function toolGetFileStatsByType($userId, $args)
    {
        $parentId = intval($args['parent_id'] ?? 0);
        
        $fm = new FileManagerService();
        $files = $fm->listFiles($parentId, 'name', 'asc', 1, 10000);
        
        $stats = [];
        $totalSize = 0;
        $totalCount = 0;
        
        foreach ($files as $file) {
            if ($file['is_dir']) continue;
            
            $type = $file['file_type'] ?? 'unknown';
            if (!isset($stats[$type])) {
                $stats[$type] = ['type' => $type, 'count' => 0, 'size' => 0, 'size_formatted' => '0 B'];
            }
            
            $stats[$type]['count']++;
            $stats[$type]['size'] += intval($file['filesize'] ?? 0);
            $totalSize += intval($file['filesize'] ?? 0);
            $totalCount++;
        }
        
        foreach ($stats as &$stat) {
            $stat['size_formatted'] = \App\Core\Security::formatSize($stat['size']);
            $stat['percentage'] = $totalSize > 0 ? round($stat['size'] / $totalSize * 100, 2) : 0;
        }
        
        usort($stats, function($a, $b) {
            return $b['size'] - $a['size'];
        });
        
        return [
            'type_stats' => $stats,
            'total_count' => $totalCount,
            'total_size' => \App\Core\Security::formatSize($totalSize),
            'message' => "共 {$totalCount} 个文件，总大小 " . \App\Core\Security::formatSize($totalSize),
        ];
    }

    private function toolCopyFile($userId, $args)
    {
        $fileId = intval($args['file_id'] ?? 0);
        $targetId = intval($args['target_parent_id'] ?? 0);
        if ($fileId <= 0) return ['error' => '请提供文件ID'];
        $fm = new FileManagerService();
        return $fm->copyFile($fileId, $targetId);
    }

    private function toolCopyFilesBatch($userId, $args)
    {
        $fileIds = $args['file_ids'] ?? [];
        $targetId = intval($args['target_parent_id'] ?? 0);
        if (empty($fileIds)) return ['error' => '请提供要复制的文件ID列表'];

        $fm = new FileManagerService();
        $result = $fm->batchCopyItems($fileIds, $targetId);

        return [
            'success' => $result['success'],
            'copied' => $result['success_count'] ?? 0,
            'failed' => $result['fail_count'] ?? 0,
            'errors' => $result['errors'] ?? [],
            'message' => $result['message'] ?? '复制完成',
        ];
    }

    private function toolGetFolderSize($userId, $args)
    {
        $folderId = intval($args['folder_id'] ?? 0);
        if ($folderId <= 0) return ['error' => '请提供文件夹ID'];

        $fm = new FileManagerService();
        $folder = $fm->getFileById($folderId);

        if (!$folder || $folder['user_id'] != $userId) {
            return ['error' => '文件夹不存在或无权限访问'];
        }

        if (!$folder['is_dir']) {
            return ['error' => '该ID对应的是文件，不是文件夹'];
        }

        // 获取文件夹下的文件数量
        $allFiles = $fm->listFiles($folderId, 'name', 'asc', 1, 0);
        $directFileCount = 0;
        $directFolderCount = 0;
        foreach ($allFiles as $f) {
            if ($f['is_dir']) {
                $directFolderCount++;
            } else {
                $directFileCount++;
            }
        }

        $totalSize = $fm->calculateFolderSize($folderId, $userId);

        return [
            'success' => true,
            'folder_name' => $folder['filename'],
            'folder_id' => $folderId,
            'total_size' => \App\Core\Security::formatSize($totalSize),
            'total_size_bytes' => $totalSize,
            'direct_files' => $directFileCount,
            'direct_folders' => $directFolderCount,
            'message' => "文件夹 '{$folder['filename']}' 总大小 " . \App\Core\Security::formatSize($totalSize) . "，包含 {$directFileCount} 个文件、{$directFolderCount} 个子文件夹",
        ];
    }

    private function toolSearchAndDelete($userId, $args)
    {
        $keyword = $args['keyword'] ?? '';
        $type = $args['type'] ?? 'all';
        $autoConfirm = isset($args['auto_confirm']) && $args['auto_confirm'];
        
        if (empty($keyword)) {
            return ['error' => '请提供搜索关键词'];
        }
        
        $fm = new FileManagerService();
        $files = $fm->searchFiles($keyword, $type, 1, 100);
        
        if (empty($files)) {
            return ['message' => '未找到匹配的文件'];
        }
        
        $fileIds = array_map(function($f) { return intval($f['id']); }, $files);
        
        if (!$autoConfirm) {
            return [
                'need_confirm' => true,
                'file_ids' => $fileIds,
                'matched_files' => array_map(function($f) {
                    return ['id' => $f['id'], 'name' => $f['filename'], 'size' => $f['filesize_formatted']];
                }, $files),
                'count' => count($files),
                'message' => "找到 " . count($files) . " 个匹配的文件，请确认是否删除。回复'确认'或'全都删除'后立即执行。",
            ];
        }
        
        $success = 0;
        $failed = 0;
        foreach ($fileIds as $fileId) {
            $result = $fm->deleteFile($fileId);
            if ($result['success']) {
                $success++;
            } else {
                $failed++;
            }
        }
        
        return [
            'success' => true,
            'deleted' => $success,
            'failed' => $failed,
            'message' => "成功删除 {$success} 个文件，失败 {$failed} 个",
        ];
    }

    private function toolSearchAndMove($userId, $args)
    {
        $keyword = $args['keyword'] ?? '';
        $type = $args['type'] ?? 'all';
        $targetParentId = intval($args['target_parent_id'] ?? 0);
        $autoConfirm = isset($args['auto_confirm']) && $args['auto_confirm'];
        
        if (empty($keyword)) {
            return ['error' => '请提供搜索关键词'];
        }
        
        if ($targetParentId <= 0) {
            return ['error' => '请提供目标目录 ID'];
        }
        
        $fm = new FileManagerService();
        $files = $fm->searchFiles($keyword, $type, 1, 100);
        
        if (empty($files)) {
            return ['message' => '未找到匹配的文件'];
        }
        
        if (!$autoConfirm) {
            return [
                'need_confirm' => true,
                'matched_files' => array_map(function($f) {
                    return ['id' => $f['id'], 'name' => $f['filename'], 'size' => $f['filesize_formatted']];
                }, $files),
                'target_parent_id' => $targetParentId,
                'count' => count($files),
                'message' => "找到 " . count($files) . " 个匹配的文件，确认移动到目标目录？",
            ];
        }
        
        $success = 0;
        $failed = 0;
        foreach ($files as $file) {
            $result = $fm->moveFile(intval($file['id']), $targetParentId);
            if ($result['success']) {
                $success++;
            } else {
                $failed++;
            }
        }
        
        return [
            'success' => true,
            'moved' => $success,
            'failed' => $failed,
            'target' => $targetParentId,
            'message' => "成功移动 {$success} 个文件，失败 {$failed} 个",
        ];
    }

    private function toolOrganizeFilesByType($userId, $args)
    {
        $parentId = intval($args['parent_id'] ?? 0);
        $autoConfirm = isset($args['auto_confirm']) && $args['auto_confirm'];
        
        $fm = new FileManagerService();
        $files = $fm->listFiles($parentId, 'name', 'asc', 1, 10000);
        
        $typeFolders = [
            'image' => null,
            'video' => null,
            'audio' => null,
            'document' => null,
            'archive' => null,
        ];
        
        $typeMap = [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif'],
            'video' => ['mp4', 'avi', 'mkv', 'mov', 'wmv', 'flv', 'webm'],
            'audio' => ['mp3', 'wav', 'flac', 'aac', 'ogg', 'wma', 'm4a', 'aiff', 'aif', 'opus', 'ape', 'alac', 'ra', 'ram', 'ac3', 'amr', 'mid', 'midi'],
            'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md'],
            'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
        ];
        
        $plan = [];
        foreach ($files as $file) {
            if ($file['is_dir']) continue;
            
            $fileType = strtolower($file['file_type']);
            $category = null;
            foreach ($typeMap as $cat => $types) {
                if (in_array($fileType, $types)) {
                    $category = $cat;
                    break;
                }
            }
            
            if ($category) {
                $plan[] = [
                    'file_id' => $file['id'],
                    'file_name' => $file['filename'],
                    'category' => $category,
                ];
            }
        }
        
        if (!$autoConfirm) {
            return [
                'need_confirm' => true,
                'plan' => $plan,
                'count' => count($plan),
                'message' => "计划整理 " . count($plan) . " 个文件到对应类型文件夹",
            ];
        }
        
        $folderCache = [];
        $moved = 0;
        $failed = 0;
        
        foreach ($plan as $item) {
            $category = $item['category'];
            if (!isset($folderCache[$category])) {
                $folderName = [
                    'image' => '图片',
                    'video' => '视频',
                    'audio' => '音频',
                    'document' => '文档',
                    'archive' => '压缩包',
                ][$category];
                
                $result = $fm->createFolder($parentId, $folderName);
                if ($result['success']) {
                    $folderCache[$category] = $result['file_id'];
                } else {
                    $existing = $fm->listFiles($parentId, 'name', 'asc', 1, 100);
                    foreach ($existing as $f) {
                        if ($f['is_dir'] && $f['filename'] === $folderName) {
                            $folderCache[$category] = $f['id'];
                            break;
                        }
                    }
                }
            }
            
            if (isset($folderCache[$category])) {
                $moveResult = $fm->moveFile($item['file_id'], $folderCache[$category]);
                if ($moveResult['success']) {
                    $moved++;
                } else {
                    $failed++;
                }
            } else {
                $failed++;
            }
        }
        
        return [
            'success' => true,
            'moved' => $moved,
            'failed' => $failed,
            'message' => "成功整理 {$moved} 个文件，失败 {$failed} 个",
        ];
    }

    private function toolGetRecentFiles($userId, $args)
    {
        $limit = intval($args['limit'] ?? 20);
        $days = intval($args['days'] ?? 7);
        
        $fm = new FileManagerService();
        $files = $fm->listFiles(0, 'date', 'desc', 1, $limit);
        
        $recentFiles = [];
        $now = time();
        $cutoffTime = $now - ($days * 24 * 60 * 60);
        
        foreach ($files as $file) {
            if ($file['is_dir']) continue;
            if (($file['created_at'] ?? 0) < $cutoffTime) continue;
            
            $recentFiles[] = [
                'id' => $file['id'],
                'name' => $file['filename'],
                'size' => $file['filesize_formatted'],
                'type' => $file['file_type'],
                'created_at' => $file['created_at_formatted'],
                'path' => $file['filepath'] ?? '/',
            ];
        }
        
        return [
            'files' => $recentFiles,
            'count' => count($recentFiles),
            'message' => "最近 {$days} 天内有 " . count($recentFiles) . " 个文件",
        ];
    }

    private function toolGetFavoriteFiles($userId, $args)
    {
        $limit = intval($args['limit'] ?? 50);
        
        $fm = new FileManagerService();
        $favorites = $fm->getFavorites(1, $limit);
        
        return [
            'files' => array_map(function($f) {
                return [
                    'id' => $f['id'],
                    'name' => $f['filename'],
                    'size' => $f['filesize_formatted'],
                    'type' => $f['file_type'],
                    'path' => $f['filepath'] ?? '/',
                ];
            }, $favorites),
            'count' => count($favorites),
            'message' => "共有 " . count($favorites) . " 个收藏文件",
        ];
    }

    private function toolBatchCreateFolders($userId, $args)
    {
        $parentId = intval($args['parent_id'] ?? 0);
        $folderNames = $args['folder_names'] ?? [];
        
        if (empty($folderNames)) {
            return ['error' => '请提供文件夹名称列表'];
        }
        
        $fm = new FileManagerService();
        $success = 0;
        $failed = 0;
        $errors = [];
        
        foreach ($folderNames as $folderName) {
            $result = $fm->createFolder($parentId, $folderName);
            if ($result['success']) {
                $success++;
            } else {
                $failed++;
                $errors[] = "{$folderName}: " . ($result['message'] ?? '创建失败');
            }
        }
        
        return [
            'success' => true,
            'created' => $success,
            'failed' => $failed,
            'errors' => $errors,
            'message' => "成功创建 {$success} 个文件夹，失败 {$failed} 个",
        ];
    }

    private function toolGetStorageUsageDetails($userId)
    {
        $fm = new FileManagerService();
        $info = $fm->getStorageInfo();
        
        $files = $fm->listFiles(0, 'name', 'asc', 1, 10000);
        $typeStats = [];
        
        foreach ($files as $file) {
            if ($file['is_dir']) continue;
            $type = $file['file_type'] ?? 'other';
            if (!isset($typeStats[$type])) {
                $typeStats[$type] = ['count' => 0, 'size' => 0];
            }
            $typeStats[$type]['count']++;
            $typeStats[$type]['size'] += intval($file['filesize'] ?? 0);
        }
        
        $sortedTypes = [];
        foreach ($typeStats as $type => $stat) {
            $sortedTypes[] = [
                'type' => $type,
                'count' => $stat['count'],
                'size' => \App\Core\Security::formatSize($stat['size']),
                'percentage' => $info['used'] > 0 ? round($stat['size'] / ($info['used'] * 1024 * 1024 * 1024) * 100, 2) : 0,
            ];
        }
        usort($sortedTypes, function($a, $b) {
            return $b['size'] - $a['size'];
        });
        
        return [
            'storage' => $info,
            'by_type' => $sortedTypes,
            'message' => "已用 {$info['used_formatted']} / {$info['total_formatted']} ({$info['usage_percent']})",
        ];
    }

    private function toolFindAndShareLargestImage($userId, $args)
    {
        $parentId = intval($args['parent_id'] ?? 0);
        $password = isset($args['password']) ? $args['password'] : '';
        $expireDays = intval($args['expire_days'] ?? 0);
        
        $fm = new FileManagerService();
        $files = $fm->listFiles($parentId, 'size', 'desc', 1, 10);
        
        $largestImage = null;
        $imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'ico', 'tiff', 'tif'];
        
        foreach ($files as $file) {
            if ($file['is_dir']) continue;
            if (in_array(strtolower($file['file_type']), $imageTypes)) {
                $largestImage = $file;
                break;
            }
        }
        
        if (!$largestImage) {
            return ['error' => '未找到图片文件'];
        }
        
        $ss = new ShareService();
        $shareResult = $ss->createShare($largestImage['id'], [
            'password' => $password,
            'expire_days' => $expireDays,
        ]);
        
        if (!$shareResult['success']) {
            return [
                'success' => false,
                'file' => [
                    'id' => $largestImage['id'],
                    'name' => $largestImage['filename'],
                    'size' => $largestImage['filesize_formatted'],
                    'filesize' => $largestImage['filesize'],
                    'path' => $largestImage['filepath'] ?? '/',
                ],
                'message' => '找到图片但创建分享失败：' . ($shareResult['message'] ?? '未知错误'),
            ];
        }
        
        return [
            'success' => true,
            'file' => [
                'id' => $largestImage['id'],
                'name' => $largestImage['filename'],
                'size' => $largestImage['filesize_formatted'],
                'filesize' => $largestImage['filesize'],
                'path' => $largestImage['filepath'] ?? '/',
                'type' => $largestImage['file_type'],
            ],
            'share' => $shareResult,
            'qrcode_svg' => $shareResult['qrcode'] ?? null,
            'message' => "成功找到最大图片 '{$largestImage['filename']}' ({$largestImage['filesize_formatted']}) 并创建分享链接",
        ];
    }

    private function toolSearchAndShare($userId, $args)
    {
        $keyword = $args['keyword'] ?? '';
        $type = $args['type'] ?? 'all';
        $password = isset($args['password']) ? $args['password'] : '';
        $expireDays = intval($args['expire_days'] ?? 0);

        if (empty($keyword)) {
            return ['error' => '请提供搜索关键词'];
        }

        $fm = new FileManagerService();
        $files = $fm->searchFiles($keyword, $type, 1, 50);

        if (empty($files)) {
            return ['message' => '未找到匹配的文件'];
        }

        if (count($files) > 1) {
            return [
                'need_confirm' => true,
                'matched_files' => array_map(function ($f) {
                    return ['id' => $f['id'], 'name' => $f['filename'], 'size' => $f['filesize_formatted'], 'type' => $f['is_dir'] ? 'folder' : $f['file_type']];
                }, $files),
                'count' => count($files),
                'message' => '找到 ' . count($files) . ' 个匹配的文件，请指定要分享的文件名或提供文件ID',
            ];
        }

        $file = $files[0];
        $ss = new ShareService();
        $shareResult = $ss->createShare(intval($file['id']), [
            'password' => $password,
            'expire_days' => $expireDays,
        ]);

        if (!$shareResult['success']) {
            return [
                'success' => false,
                'file' => [
                    'id' => $file['id'],
                    'name' => $file['filename'],
                    'size' => $file['filesize_formatted'],
                ],
                'message' => '找到文件但创建分享失败：' . ($shareResult['message'] ?? '未知错误'),
            ];
        }

        return [
            'success' => true,
            'file' => [
                'id' => $file['id'],
                'name' => $file['filename'],
                'size' => $file['filesize_formatted'],
                'type' => $file['is_dir'] ? 'folder' : $file['file_type'],
            ],
            'share' => $shareResult,
            'qrcode_svg' => $shareResult['qrcode'] ?? null,
            'message' => "成功为 '{$file['filename']}' ({$file['filesize_formatted']}) 创建分享链接",
        ];
    }

    private function toolGetFileTree($userId, $args)
    {
        $parentId = intval($args['parent_id'] ?? 0);
        $maxDepth = intval($args['max_depth'] ?? 3);
        $maxNodes = intval($args['max_nodes'] ?? 500);
        $includeFiles = isset($args['include_files']) ? $args['include_files'] : true;

        // 缓存键：基于用户ID、目录ID和参数
        $cacheKey = "file_tree_{$userId}_{$parentId}_{$maxDepth}_{$maxNodes}_" . ($includeFiles ? '1' : '0');
        $cacheFile = DATA_PATH . '/.ai_tree_' . md5($cacheKey) . '.json';
        $cacheTTL = 30; // 缓存30秒

        // 检查缓存
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached) {
                return array_merge($cached, ['cached' => true]);
            }
        }

        $fm = new FileManagerService();
        $nodeCount = 0;

        // 递归构建树
        $buildTree = function($pid, $depth) use (&$buildTree, $fm, $maxDepth, $maxNodes, $includeFiles, &$nodeCount) {
            if ($depth > $maxDepth || $nodeCount >= $maxNodes) {
                return null;
            }

            // 获取当前目录下的所有项目（不分页，使用 page_size=0）
            $items = $fm->listFiles($pid, 'name', 'asc', 1, 0);
            $result = [];

            foreach ($items as $item) {
                if ($nodeCount >= $maxNodes) break;

                $nodeCount++;
                $node = [
                    'id' => $item['id'],
                    'name' => $item['filename'],
                    'type' => $item['is_dir'] ? 'folder' : ($item['file_type'] ?? 'file'),
                ];

                if (!$item['is_dir']) {
                    if ($includeFiles) {
                        $node['size'] = $item['filesize_formatted'];
                        $node['size_bytes'] = intval($item['filesize'] ?? 0);
                    }
                } else {
                    // 递归获取子目录
                    $children = $buildTree($item['id'], $depth + 1);
                    if ($children !== null) {
                        $node['children'] = $children;
                        $node['child_count'] = count($children);
                    }
                }

                $result[] = $node;
            }

            return $result;
        };

        $tree = $buildTree($parentId, 1);

        // 统计信息
        $stats = [
            'total_nodes' => $nodeCount,
            'folder_count' => 0,
            'file_count' => 0,
            'total_size' => 0,
        ];

        $countNodes = function($nodes) use (&$countNodes, &$stats) {
            foreach ($nodes as $node) {
                if ($node['type'] === 'folder') {
                    $stats['folder_count']++;
                    if (isset($node['children'])) {
                        $countNodes($node['children']);
                    }
                } else {
                    $stats['file_count']++;
                    $stats['total_size'] += $node['size_bytes'] ?? 0;
                }
            }
        };

        if (!empty($tree)) {
            $countNodes($tree);
        }

        $result = [
            'tree' => $tree,
            'parent_id' => $parentId,
            'max_depth' => $maxDepth,
            'node_count' => $nodeCount,
            'folder_count' => $stats['folder_count'],
            'file_count' => $stats['file_count'],
            'total_size' => \App\Core\Security::formatSize($stats['total_size']),
            'truncated' => $nodeCount >= $maxNodes,
            'message' => "目录树包含 {$stats['folder_count']} 个文件夹、{$stats['file_count']} 个文件，总大小 " . \App\Core\Security::formatSize($stats['total_size']),
        ];

        // 写入缓存
        file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE), LOCK_EX);

        return $result;
    }

    private function toolPreviewFile($userId, $args)
    {
        $fileId = intval($args['file_id'] ?? 0);
        $maxLength = intval($args['max_length'] ?? 5000);
        if ($maxLength <= 0 || $maxLength > 50000) {
            $maxLength = 5000;
        }

        if ($fileId <= 0) {
            return ['error' => '请提供文件ID'];
        }

        $fm = new FileManagerService();
        $file = $fm->getFileById($fileId);

        if (!$file || $file['user_id'] != $userId) {
            return ['error' => '文件不存在或无权限访问'];
        }

        if ($file['is_dir']) {
            return ['error' => '无法预览文件夹，请使用 list_files 或 scan_files 查看目录内容'];
        }

        // 直接使用数据库中的 filepath，避免路径拼接不一致
        $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $file['filepath'];

        if (!Security::isSafeFilePath($fullPath)) {
            return ['error' => '文件路径异常'];
        }

        if (!file_exists($fullPath)) {
            return ['error' => '文件物理路径不存在'];
        }

        $fileSize = filesize($fullPath);
        $ext = strtolower($file['file_type'] ?? pathinfo($file['filename'], PATHINFO_EXTENSION));

        // 文本类文件直接读取
        $textExts = ['txt', 'md', 'json', 'xml', 'html', 'htm', 'css', 'js', 'php', 'py', 'java', 'c', 'cpp', 'h', 'go', 'rs', 'sql', 'yaml', 'yml', 'ini', 'conf', 'log', 'csv', 'tsv', 'sh', 'bat', 'ps1'];

        $content = '';
        $isText = false;

        if (in_array($ext, $textExts)) {
            $isText = true;
            $content = file_get_contents($fullPath);
            if ($content === false) {
                return ['error' => '读取文件失败'];
            }
        } elseif ($ext === 'pdf') {
            // 尝试提取PDF文本
            $content = $this->extractPdfText($fullPath, $maxLength);
        } elseif (in_array($ext, ['doc', 'docx'])) {
            $content = $this->extractDocxText($fullPath, $maxLength);
        } elseif (in_array($ext, ['xls', 'xlsx'])) {
            $content = $this->extractExcelText($fullPath, $maxLength);
        } elseif (in_array($ext, ['ppt', 'pptx'])) {
            $content = $this->extractPptxText($fullPath, $maxLength);
        } else {
            return [
                'error' => '暂不支持该格式的内容预览',
                'file_name' => $file['filename'],
                'file_type' => $ext,
                'file_size' => $file['filesize_formatted'],
                'supported_types' => implode(', ', array_merge($textExts, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'])),
            ];
        }

        $originalLength = mb_strlen($content, 'UTF-8');
        $truncated = false;

        if ($originalLength > $maxLength) {
            $content = mb_substr($content, 0, $maxLength, 'UTF-8') . "\n\n[内容已截断，共 {$originalLength} 字符，仅展示前 {$maxLength} 字符]";
            $truncated = true;
        }

        return [
            'success' => true,
            'file_name' => $file['filename'],
            'file_type' => $ext,
            'file_size' => $file['filesize_formatted'],
            'content' => $content,
            'content_length' => $originalLength,
            'truncated' => $truncated,
            'is_text' => $isText,
            'message' => "成功读取 '{$file['filename']}'" . ($truncated ? "（已截断）" : ""),
        ];
    }

    private function toolSearchFileContent($userId, $args)
    {
        $keyword = $args['keyword'] ?? '';
        $parentId = intval($args['parent_id'] ?? 0);
        $maxResults = intval($args['max_results'] ?? 20);
        if ($maxResults <= 0 || $maxResults > 50) {
            $maxResults = 20;
        }

        if (empty($keyword)) {
            return ['error' => '请提供搜索关键词'];
        }

        $fm = new FileManagerService();
        // 获取所有文件（不分页）
        $allFiles = $fm->listFiles($parentId, 'name', 'asc', 1, 0);

        $textExts = ['txt', 'md', 'json', 'xml', 'html', 'htm', 'css', 'js', 'php', 'py', 'java', 'c', 'cpp', 'h', 'go', 'rs', 'sql', 'yaml', 'yml', 'ini', 'conf', 'log', 'csv', 'tsv'];
        $officeExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
        $supportedExts = array_merge($textExts, $officeExts);

        $matches = [];
        $searchedCount = 0;
        $maxSearchFiles = 200; // 最多搜索200个文件，防止超时

        foreach ($allFiles as $file) {
            if ($file['is_dir']) continue;
            if (count($matches) >= $maxResults) break;
            if ($searchedCount >= $maxSearchFiles) break;

            $ext = strtolower($file['file_type'] ?? pathinfo($file['filename'], PATHINFO_EXTENSION));
            if (!in_array($ext, $supportedExts)) continue;

            $searchedCount++;

            // 构建文件路径
            $breadcrumb = $fm->getBreadcrumb($file['parent_id']);
            $relativePath = '';
            foreach ($breadcrumb as $folder) {
                $relativePath .= $folder['filename'] . DIRECTORY_SEPARATOR;
            }
            $relativePath .= $file['filename'];
            $fullPath = FILES_PATH . DIRECTORY_SEPARATOR . $relativePath;

            if (!file_exists($fullPath)) continue;

            // 读取内容
            $content = '';
            if (in_array($ext, $textExts)) {
                $content = @file_get_contents($fullPath);
            } elseif ($ext === 'pdf') {
                $content = $this->extractPdfText($fullPath, 10000);
            } elseif (in_array($ext, ['doc', 'docx'])) {
                $content = $this->extractDocxText($fullPath, 10000);
            } elseif (in_array($ext, ['xls', 'xlsx'])) {
                $content = $this->extractExcelText($fullPath, 10000);
            } elseif (in_array($ext, ['ppt', 'pptx'])) {
                $content = $this->extractPptxText($fullPath, 10000);
            }

            if (empty($content)) continue;

            // 搜索关键词（不区分大小写）
            $lowerContent = mb_strtolower($content, 'UTF-8');
            $lowerKeyword = mb_strtolower($keyword, 'UTF-8');

            if (mb_strpos($lowerContent, $lowerKeyword, 0, 'UTF-8') === false) {
                continue;
            }

            // 提取匹配片段（前后各100字符）
            $pos = mb_strpos($lowerContent, $lowerKeyword, 0, 'UTF-8');
            $start = max(0, $pos - 100);
            $length = min(300, mb_strlen($content, 'UTF-8') - $start);
            $snippet = mb_substr($content, $start, $length, 'UTF-8');
            if ($start > 0) $snippet = '...' . $snippet;
            if ($start + $length < mb_strlen($content, 'UTF-8')) $snippet .= '...';

            // 高亮关键词
            $snippet = str_ireplace($keyword, '【' . $keyword . '】', $snippet);

            $matches[] = [
                'id' => $file['id'],
                'name' => $file['filename'],
                'type' => $ext,
                'size' => $file['filesize_formatted'],
                'snippet' => $snippet,
                'match_count' => substr_count($lowerContent, $lowerKeyword),
            ];
        }

        $result = [
            'success' => true,
            'keyword' => $keyword,
            'matches' => $matches,
            'count' => count($matches),
            'searched_files' => $searchedCount,
            'message' => count($matches) > 0
                ? "在 {$searchedCount} 个文件中搜索，找到 " . count($matches) . " 个匹配文件"
                : "在 {$searchedCount} 个文件中搜索，未找到包含 '{$keyword}' 的文件",
        ];

        // Truncate snippets to 150 chars
        if (isset($result['matches']) && is_array($result['matches'])) {
            foreach ($result['matches'] as &$match) {
                if (is_array($match)) {
                    if (isset($match['snippet']) && mb_strlen($match['snippet']) > 150) {
                        $match['snippet'] = mb_substr($match['snippet'], 0, 150) . '...';
                    }
                    if (isset($match['content']) && mb_strlen($match['content']) > 150) {
                        $match['content'] = mb_substr($match['content'], 0, 150) . '...';
                    }
                    if (isset($match['match']) && mb_strlen($match['match']) > 150) {
                        $match['match'] = mb_substr($match['match'], 0, 150) . '...';
                    }
                }
            }
            unset($match);
        }

        // Also truncate if >20 matches
        if (isset($result['matches']) && is_array($result['matches']) && count($result['matches']) > 20) {
            $totalCount = count($result['matches']);
            $result['matches'] = array_slice($result['matches'], 0, 20);
            $result['truncated'] = true;
            $result['total_count'] = $totalCount;
            $result['truncation_note'] = "仅显示前20个匹配，共{$totalCount}个";
        }

        return $result;
    }

    private function extractPdfText($path, $maxLength)
    {
        // 优先使用 pdftotext (poppler-utils)
        $pdftotext = shell_exec('which pdftotext 2>/dev/null || where pdftotext 2>nul');
        if (!empty($pdftotext)) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'pdf_');
            shell_exec('pdftotext "' . escapeshellarg($path) . '" "' . escapeshellarg($tmpFile) . '" 2>/dev/null');
            if (file_exists($tmpFile)) {
                $text = file_get_contents($tmpFile);
                unlink($tmpFile);
                if (!empty($text)) {
                    return $text;
                }
            }
        }

        // 回退：尝试用 PHP 读取原始内容并提取文本片段
        $raw = file_get_contents($path);
        if ($raw === false) return '[PDF 读取失败]';

        // 简单提取 stream 中的文本
        $text = '';
        if (preg_match_all('/stream\s*(.*?)\s*endstream/s', $raw, $matches)) {
            foreach ($matches[1] as $stream) {
                // 尝试解压
                $decompressed = @gzuncompress($stream);
                if ($decompressed !== false) {
                    $stream = $decompressed;
                }
                // 提取括号内的文本
                if (preg_match_all('/\(([^)]+)\)/', $stream, $textMatches)) {
                    foreach ($textMatches[1] as $t) {
                        // 处理转义
                        $t = str_replace(['\\(', '\\)', '\\\\', '\\n', '\\r', '\\t'], ['(', ')', '\\', "\n", "\r", "\t"], $t);
                        $text .= $t . ' ';
                    }
                }
            }
        }

        return !empty($text) ? $text : '[PDF 文本提取失败，可能需要安装 pdftotext]';
    }

    private function extractDocxText($path, $maxLength)
    {
        $text = '';
        $zip = new \ZipArchive();
        if ($zip->open($path) === true) {
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml) {
                // 去除 XML 标签保留文本
                $text = strip_tags(str_replace(['<w:p>', '<w:br/>', '</w:p>'], ["\n\n", "\n", "\n\n"], $xml));
                // 清理多余空白
                $text = preg_replace('/\s+/', ' ', $text);
            }
        }
        return !empty($text) ? $text : '[DOCX 文本提取失败]';
    }

    private function extractExcelText($path, $maxLength)
    {
        $text = '';
        $zip = new \ZipArchive();
        if ($zip->open($path) === true) {
            // 读取 shared strings
            $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
            $sharedStrings = [];
            if ($sharedStringsXml) {
                preg_match_all('/<t>([^<]*)<\/t>/', $sharedStringsXml, $matches);
                $sharedStrings = $matches[1];
            }

            // 读取第一个 worksheet
            $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->close();

            if ($sheetXml) {
                preg_match_all('/<c[^>]*>(?:<v>([^<]*)<\/v>|<is><t>([^<]*)<\/t><\/is>)<\/c>/', $sheetXml, $matches, PREG_SET_ORDER);
                $rows = [];
                $currentRow = [];
                $lastRow = 0;

                foreach ($matches as $match) {
                    // 简单提取，不处理复杂表格结构
                    $val = $match[2] !== '' ? $match[2] : ($sharedStrings[intval($match[1])] ?? $match[1]);
                    $currentRow[] = $val;
                }

                $text = implode("\t", $currentRow);
            }
        }
        return !empty($text) ? $text : '[Excel 文本提取失败]';
    }

    private function extractPptxText($path, $maxLength)
    {
        $text = '';
        $zip = new \ZipArchive();
        if ($zip->open($path) === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (strpos($name, 'ppt/slides/slide') === 0 && substr($name, -4) === '.xml') {
                    $xml = $zip->getFromIndex($i);
                    if ($xml) {
                        preg_match_all('/<a:t>([^<]*)<\/a:t>/', $xml, $matches);
                        if (!empty($matches[1])) {
                            $text .= implode(' ', $matches[1]) . "\n\n";
                        }
                    }
                }
            }
            $zip->close();
        }
        return !empty($text) ? $text : '[PPTX 文本提取失败]';
    }

    private function generateQRCodeSVG($data)
    {
        // 优先使用 chillerlan/php-qrcode 真实库生成可扫描的二维码
        // composer 不可用时返回空串，由前端 JS 库基于 share URL 自行生成（前端由其他代理处理）
        if (class_exists(\chillerlan\QRCode\QRCode::class)) {
            try {
                $qr = new \chillerlan\QRCode\QRCode();
                $pngData = $qr->render($data);
                return base64_encode($pngData);
            } catch (\Throwable $e) {
                return '';
            }
        }
        return '';
    }

    /**
     * Classify error type for intelligent retry decisions
     */
    private function classifyError(string $errorMsg): string
    {
        $lower = strtolower($errorMsg);
        if (str_contains($lower, '不存在') || str_contains($lower, 'not found') || str_contains($lower, '找不到') || str_contains($lower, 'no such')) {
            return 'not_found';
        }
        if (str_contains($lower, '权限') || str_contains($lower, 'permission') || str_contains($lower, 'forbidden') || str_contains($lower, 'unauthorized')) {
            return 'permission_denied';
        }
        if (str_contains($lower, '网络') || str_contains($lower, 'network') || str_contains($lower, 'timeout') || str_contains($lower, '连接') || str_contains($lower, 'curl')) {
            return 'network_error';
        }
        if (str_contains($lower, '参数') || str_contains($lower, 'param') || str_contains($lower, 'invalid') || str_contains($lower, '格式') || str_contains($lower, '频繁')) {
            return 'param_error';
        }
        return 'unknown';
    }

    /**
     * 管理 TODO 任务清单
     */
    private function toolManageTodo($userId, $args)
    {
        $action = $args['action'] ?? 'list';
        $sessionId = $args['session_id'] ?? ($_SESSION['ai_session_id'] ?? '');

        if (empty($sessionId)) {
            return ['error' => '缺少 session_id，无法管理 TODO'];
        }

        switch ($action) {
            case 'add':
                $items = $args['items'] ?? [];
                if (!is_array($items) || empty($items)) {
                    $content = $args['content'] ?? '';
                    if (empty($content)) {
                        return ['error' => '请提供 content 或 items 参数', 'error_type' => 'param_error'];
                    }
                    $items = [['content' => $content]];
                }
                $added = [];
                foreach ($items as $idx => $item) {
                    $content = is_string($item) ? $item : ($item['content'] ?? '');
                    if (empty($content)) continue;

                    $id = $this->db->insert('ai_agent_todos', [
                        'session_id' => $sessionId,
                        'user_id' => $userId,
                        'content' => $content,
                        'status' => 'pending',
                        'created_at' => time(),
                        'updated_at' => time(),
                        'order_idx' => $idx,
                    ]);
                    $added[] = ['id' => $id, 'content' => $content, 'status' => 'pending'];
                }
                $allTodos = $this->fetchTodos($sessionId);
                return ['success' => true, 'added' => $added, 'todos' => $allTodos, 'message' => '已添加 ' . count($added) . ' 个任务'];

            case 'update':
                $id = intval($args['id'] ?? 0);
                $content = $args['content'] ?? null;
                $status = $args['status'] ?? null;
                if ($id <= 0) {
                    return ['error' => '缺少 id 参数', 'error_type' => 'param_error'];
                }
                $fields = ['updated_at' => time()];
                if ($content !== null) $fields['content'] = $content;
                if ($status !== null) $fields['status'] = $status;
                $this->db->update('ai_agent_todos', $fields, 'id = ? AND session_id = ? AND user_id = ?', [$id, $sessionId, $userId]);
                $allTodos = $this->fetchTodos($sessionId);
                return ['success' => true, 'todos' => $allTodos, 'message' => "任务 #{$id} 已更新"];

            case 'complete':
                $id = intval($args['id'] ?? 0);
                if ($id <= 0) {
                    // Support completing by ids array
                    $ids = $args['ids'] ?? [];
                    if (!empty($ids)) {
                        foreach ($ids as $tid) {
                            $this->db->update('ai_agent_todos', ['status' => 'completed', 'updated_at' => time()], 'id = ? AND session_id = ? AND user_id = ?', [intval($tid), $sessionId, $userId]);
                        }
                        $allTodos = $this->fetchTodos($sessionId);
                        return ['success' => true, 'todos' => $allTodos, 'message' => '已标记完成'];
                    }
                    return ['error' => '缺少 id 参数', 'error_type' => 'param_error'];
                }
                $this->db->update('ai_agent_todos', ['status' => 'completed', 'updated_at' => time()], 'id = ? AND session_id = ? AND user_id = ?', [$id, $sessionId, $userId]);
                $allTodos = $this->fetchTodos($sessionId);
                return ['success' => true, 'todos' => $allTodos, 'message' => "任务 #{$id} 已完成"];

            case 'list':
                $allTodos = $this->fetchTodos($sessionId);
                $completed = count(array_filter($allTodos, fn($t) => $t['status'] === 'completed'));
                $total = count($allTodos);
                return ['success' => true, 'todos' => $allTodos, 'total' => $total, 'completed' => $completed, 'message' => "共 {$total} 项，已完成 {$completed} 项"];

            case 'clear':
                $this->db->delete('ai_agent_todos', 'session_id = ? AND user_id = ?', [$sessionId, $userId]);
                return ['success' => true, 'todos' => [], 'message' => '已清空所有 TODO'];

            default:
                return ['error' => '未知操作: ' . $action, 'error_type' => 'param_error'];
        }
    }

    private function fetchTodos(string $sessionId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, content, status, order_idx FROM ai_agent_todos WHERE session_id = ? ORDER BY order_idx ASC, id ASC",
            [$sessionId]
        );
        return $rows ?: [];
    }

    /**
     * 派发子 agent 执行独立子任务
     */
    private function toolSpawnSubagent($userId, $args)
    {
        $task = $args['task'] ?? '';
        $contextData = $args['context'] ?? [];
        $allowedTools = $args['tools'] ?? null;

        if (empty($task)) {
            return ['error' => '缺少 task 参数（子任务描述）', 'error_type' => 'param_error'];
        }

        if (empty($this->apiKey) && !$this->isLocalUrl($this->baseUrl)) {
            return ['error' => '请先配置 AI API Key', 'error_type' => 'param_error'];
        }

        try {
            $subAgent = new AIAgentService();

            // Build sub-agent messages - independent context
            $subMessages = [
                [
                    'role' => 'user',
                    'content' => $task . (!empty($contextData) ? "\n\n上下文信息：" . json_encode($contextData, JSON_UNESCAPED_UNICODE) : ''),
                ],
            ];

            // Execute sub-agent synchronously (non-stream)
            $result = $subAgent->chat($subMessages, false, $contextData);

            if (!$result['success']) {
                return ['error' => '子 agent 执行失败: ' . ($result['message'] ?? '未知错误'), 'error_type' => 'unknown'];
            }

            return [
                'success' => true,
                'task' => $task,
                'result' => $result['message'],
                'tool_results' => $result['tool_results'] ?? [],
                'message' => '子 agent 已完成任务',
            ];
        } catch (\Exception $e) {
            return ['error' => '子 agent 异常: ' . $e->getMessage(), 'error_type' => 'unknown'];
        }
    }

    /**
     * 判断 URL 是否为本地地址（用于决定是否需要 API Key）
     */
    private function isLocalUrl($url)
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) return false;
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0', '[::1]'])) return true;
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }
        return false;
    }
}
