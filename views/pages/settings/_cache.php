                    <div id="settingsTabCache" class="settings-tab-content">
                        <div class="settings-card glass">
                            <h3>缓存管理</h3>
                            <div class="cache-stats" id="cacheStats">
                                <div class="cache-item">
                                    <div class="cache-info">
                                        <div class="cache-label">缩略图缓存</div>
                                        <div class="cache-size" id="thumbCacheSize">计算中...</div>
                                    </div>
                                    <button class="btn btn-glass btn-sm" data-action="clear-cache-thumbnails"><i class="fas fa-broom"></i> 清理</button>
                                </div>
                                <div class="cache-item">
                                    <div class="cache-info">
                                        <div class="cache-label">音频封面缓存</div>
                                        <div class="cache-size" id="coverCacheSize">计算中...</div>
                                    </div>
                                    <button class="btn btn-glass btn-sm" data-action="clear-cache-covers"><i class="fas fa-broom"></i> 清理</button>
                                </div>
                            </div>
                            <button class="btn btn-danger" data-action="clear-all-cache"><i class="fas fa-trash-alt"></i> 清理所有缓存</button>
                        </div>
                        <div class="settings-card glass">
                            <h3>预览设置</h3>
                            <div class="settings-row">
                                <label>缩略图尺寸</label>
                                <input type="number" id="cfg_thumbnail_size" value="<?php echo $config->get('thumbnail_size'); ?>" min="32" max="500" placeholder="32-500">
                            </div>
                            <p class="settings-hint">预览大小限制：文本2MB，图片10MB，视频/音频150MB，Office文档150MB，PDF文档150MB</p>
                            <button class="btn btn-primary" data-action="save-config"><i class="fas fa-save"></i> 保存设置</button>
                        </div>
                    </div>
