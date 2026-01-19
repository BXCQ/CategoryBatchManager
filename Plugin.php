<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;

/**
 * 文章分类批量管理 (Category Batch Manager)
 *
 * 在文章管理列表页提供：
 * - 批量移动/添加/移除分类
 * - 单篇文章快速分类修改
 *
 * @package    CategoryBatchManager
 * @author     璇
 * @version    1.0.0
 * @link       https://github.com/BXCQ/CategoryBatchManager
 */
class CategoryBatchManager_Plugin implements PluginInterface
{
    /**
     * 激活插件
     *
     * @throws \Typecho\Plugin\Exception
     */
    public static function activate()
    {
        // 环境检查：PHP >= 7.0，Typecho >= 1.2.1
        if (version_compare(PHP_VERSION, '7.0.0', '<')) {
            throw new \Typecho\Plugin\Exception(_t('CategoryBatchManager 需要 PHP 7.0 或更高版本'));
        }

        if (defined('Typecho\\Common::VERSION')) {
            $typechoVersion = \Typecho\Common::VERSION;
        } else {
            $raw = \Helper::options()->version;
            $parts = explode('/', $raw);
            $typechoVersion = $parts[0];
        }

        if (version_compare($typechoVersion, '1.2.1', '<')) {
            throw new \Typecho\Plugin\Exception(_t('CategoryBatchManager 需要 Typecho 1.2.1 或更高版本'));
        }

        // 在后台页脚注入 JS/CSS 和分类选择框
        \Typecho\Plugin::factory('admin/footer.php')->end = [__CLASS__, 'renderFooter'];

        // 注册批量分类操作的 Action
        \Helper::addAction('category-batch', 'CategoryBatchManager_Action');

        return _t('文章分类批量管理插件已启用');
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        \Helper::removeAction('category-batch');
    }

    /**
     * 插件配置面板（当前无配置项，预留）
     */
    public static function config(Form $form)
    {
    }

    /**
     * 个人用户配置面板（当前无配置项）
     */
    public static function personalConfig(Form $form)
    {
    }

    /**
     * 在后台页脚输出分类批量管理相关的 HTML / JS / CSS
     */
    public static function renderFooter()
    {
        $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ('manage-posts.php' !== $scriptName) {
            // 仅在文章管理列表页注入
            return;
        }

        $options = \Helper::options();
        $actionUrl = \Typecho\Common::url('action/category-batch', $options->index);

        // 获取所有分类，按层级输出
        \Widget\Metas\Category\Rows::alloc()->to($category);
        ?>
<style>
.cbm-mask {
    position: fixed;
    left: 0;
    top: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, .3);
    z-index: 9998;
}
.cbm-modal {
    position: fixed;
    left: 50%;
    top: 20%;
    transform: translateX(-50%);
    width: 420px;
    max-width: 95%;
    background: #fff;
    border-radius: 4px;
    box-shadow: 0 2px 10px rgba(0,0,0,.2);
    z-index: 9999;
}
.cbm-modal-header {
    padding: 10px 15px;
    border-bottom: 1px solid #ddd;
    font-weight: bold;
}
.cbm-modal-body {
    padding: 10px 15px;
    max-height: 320px;
    overflow: auto;
}
.cbm-modal-footer {
    padding: 10px 15px;
    text-align: right;
    border-top: 1px solid #ddd;
}
.cbm-hidden {display: none;}
.cbm-category-search {
    width: 100%;
    box-sizing: border-box;
    margin-bottom: 8px;
}
.cbm-category-list {
    margin: 0;
    padding: 0;
    list-style: none;
}
.cbm-category-list li {
    margin: 2px 0;
    white-space: nowrap;
}
.cbm-category-list label {
    cursor: pointer;
}
.cbm-category-indent-0 {}
.cbm-category-indent-1 {padding-left: 12px;}
.cbm-category-indent-2 {padding-left: 24px;}
.cbm-category-indent-3 {padding-left: 36px;}
.cbm-category-indent-4 {padding-left: 48px;}
.cbm-category-indent-5 {padding-left: 60px;}
.cbm-quick-link {
    margin-left: 4px;
    font-size: 12px;
    color: #999;
}
</style>
<div id="cbm-mask" class="cbm-mask cbm-hidden"></div>
<div id="cbm-modal" class="cbm-modal cbm-hidden" role="dialog" aria-modal="true">
    <div class="cbm-modal-header">
        <span><?php _e('选择分类'); ?></span>
        <span id="cbm-current-op" style="float:right;color:#999;"></span>
    </div>
    <div class="cbm-modal-body">
        <input type="text" id="cbm-search" class="text cbm-category-search" placeholder="<?php _e('搜索分类'); ?>" />
        <ul id="cbm-category-list" class="cbm-category-list">
            <?php while ($category->next()): ?>
                <li data-mid="<?php $category->mid(); ?>"
                    data-name="<?php $category->name(); ?>"
                    class="cbm-category-indent-<?php echo (int)$category->levels; ?>">
                    <label>
                        <input type="checkbox" value="<?php $category->mid(); ?>" />
                        <?php echo str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', (int)$category->levels); ?><?php $category->name(); ?>
                    </label>
                </li>
            <?php endwhile; ?>
        </ul>
    </div>
    <div class="cbm-modal-footer">
        <button type="button" class="btn" id="cbm-cancel"><?php _e('取消'); ?></button>
        <button type="button" class="btn primary" id="cbm-confirm"><?php _e('确定'); ?></button>
    </div>
</div>
<script>
(function () {
    var actionUrl = <?php echo json_encode($actionUrl); ?>;
    var mask = document.getElementById('cbm-mask');
    var modal = document.getElementById('cbm-modal');
    if (!mask || !modal) {
        return;
    }

    var searchInput = document.getElementById('cbm-search');
    var list = document.getElementById('cbm-category-list');
    var confirmBtn = document.getElementById('cbm-confirm');
    var cancelBtn = document.getElementById('cbm-cancel');
    var opLabel = document.getElementById('cbm-current-op');

    var currentOp = null; // move / add / remove
    var currentCids = [];
    var categoryIndex = null; // 列索引

    function getCategoryIndex() {
        if (categoryIndex !== null) {
            return categoryIndex;
        }
        var ths = document.querySelectorAll('.typecho-list-table thead th');
        for (var i = 0; i < ths.length; i++) {
            var text = (ths[i].textContent || '').replace(/\s+/g, '');
            if (text === '<?php _e('分类'); ?>') {
                categoryIndex = i;
                break;
            }
        }
        return categoryIndex;
    }

    function setOpLabel(op) {
        var text = '';
        if (op === 'move') {
            text = '<?php _e('移动到分类'); ?>';
        } else if (op === 'add') {
            text = '<?php _e('添加分类'); ?>';
        } else if (op === 'remove') {
            text = '<?php _e('移除分类'); ?>';
        }
        if (opLabel) {
            opLabel.textContent = text;
        }
    }

    function openModal(op, cids, presetMids) {
        currentOp = op;
        currentCids = cids || [];
        setOpLabel(op);

        // 重置选中
        var inputs = list ? list.querySelectorAll('input[type="checkbox"]') : [];
        for (var i = 0; i < inputs.length; i++) {
            inputs[i].checked = false;
        }

        // 预选中（单篇文章快速修改时使用）
        if (presetMids && presetMids.length && list) {
            var presetMap = {};
            for (var j = 0; j < presetMids.length; j++) {
                presetMap[String(presetMids[j])] = true;
            }
            for (var k = 0; k < inputs.length; k++) {
                if (presetMap[inputs[k].value]) {
                    inputs[k].checked = true;
                }
            }
        }

        mask.classList.remove('cbm-hidden');
        modal.classList.remove('cbm-hidden');
    }

    function closeModal() {
        mask.classList.add('cbm-hidden');
        modal.classList.add('cbm-hidden');
    }

    function collectSelectedMids() {
        var mids = [];
        if (!list) {
            return mids;
        }
        var inputs = list.querySelectorAll('input[type="checkbox"]');
        for (var i = 0; i < inputs.length; i++) {
            if (inputs[i].checked) {
                mids.push(inputs[i].value);
            }
        }
        return mids;
    }

    function ajaxBatch(op, cids, mids) {
        if (!cids || !cids.length || !mids || !mids.length) {
            return;
        }
        var params = [];
        params.push('do=batch');
        params.push('op=' + encodeURIComponent(op));
        for (var i = 0; i < cids.length; i++) {
            params.push('cid[]=' + encodeURIComponent(cids[i]));
        }
        for (var j = 0; j < mids.length; j++) {
            params.push('mid[]=' + encodeURIComponent(mids[j]));
        }
        var body = params.join('&');

        fetch(actionUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            credentials: 'same-origin',
            body: body
        }).then(function (resp) {
            return resp.json();
        }).then(function (data) {
            if (!data || !data.success) {
                alert((data && data.message) || '操作失败');
                return;
            }
            updateTable(op, cids, mids);
        }).catch(function () {
            alert('请求失败');
        });
    }

    function updateTable(op, cids, mids) {
        var colIndex = getCategoryIndex();
        if (colIndex == null) {
            return;
        }
        var midToName = {};
        if (list) {
            var lis = list.querySelectorAll('li[data-mid]');
            for (var i = 0; i < lis.length; i++) {
                midToName[lis[i].getAttribute('data-mid')] = lis[i].getAttribute('data-name');
            }
        }

        cids.forEach(function (cid) {
            var checkbox = document.querySelector('.typecho-list-table tbody input[name="cid[]"][value="' + cid + '"]');
            if (!checkbox) {
                return;
            }
            var tr = checkbox.closest('tr');
            if (!tr) {
                return;
            }
            var tds = tr.getElementsByTagName('td');
            if (!tds || tds.length <= colIndex) {
                return;
            }
            var cell = tds[colIndex];
            // 解析当前分类 mid
            var exists = [];
            var links = cell.querySelectorAll('a[href*="manage-posts.php?category="]');
            for (var i = 0; i < links.length; i++) {
                var href = links[i].getAttribute('href') || '';
                var match = href.match(/category=(\d+)/);
                if (match) {
                    exists.push(match[1]);
                }
            }

            var now = exists.slice();
            if (op === 'move') {
                now = mids.slice();
            } else if (op === 'add') {
                mids.forEach(function (m) {
                    if (now.indexOf(m) === -1) {
                        now.push(m);
                    }
                });
            } else if (op === 'remove') {
                now = now.filter(function (m) {
                    return mids.indexOf(m) === -1;
                });
            }

            // 重建单元格内容
            var pieces = [];
            for (var j = 0; j < now.length; j++) {
                var mid = now[j];
                var name = midToName[mid] || ('#' + mid);
                var url = '<?php echo $options->adminUrl; ?>manage-posts.php?category=' + encodeURIComponent(mid);
                pieces.push('<a href="' + url + '">' + name + '</a>');
            }
            var quickHtml = '<a href="javascript:;" class="cbm-quick-link" data-cbm-op="single" data-cid="' + cid + '"><?php _e('分类'); ?></a>';
            cell.innerHTML = (pieces.length ? pieces.join(', ') + ' ' : '') + quickHtml;
        });
    }

    if (searchInput && list) {
        searchInput.addEventListener('input', function () {
            var keyword = this.value.toLowerCase();
            var items = list.querySelectorAll('li[data-mid]');
            for (var i = 0; i < items.length; i++) {
                var name = (items[i].getAttribute('data-name') || '').toLowerCase();
                items[i].style.display = (!keyword || name.indexOf(keyword) !== -1) ? '' : 'none';
            }
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            closeModal();
        });
    }
    mask.addEventListener('click', closeModal);

    if (confirmBtn) {
        confirmBtn.addEventListener('click', function () {
            var mids = collectSelectedMids();
            if (!mids.length) {
                alert('<?php _e('请至少选择一个分类'); ?>');
                return;
            }
            if (!currentCids.length) {
                closeModal();
                return;
            }
            ajaxBatch(currentOp || 'move', currentCids, mids);
            closeModal();
        });
    }

    // 批量操作菜单中增加三个选项
    var menus = document.querySelectorAll('.typecho-list-operate .dropdown-menu');
    menus.forEach ? menus.forEach(addMenuItems) : Array.prototype.forEach.call(menus, addMenuItems);

    function addMenuItems(menu) {
        if (menu.querySelector('a[data-cbm-op]')) {
            return;
        }
        var ops = [
            {op: 'move', text: '<?php _e('移动到分类...'); ?>'},
            {op: 'add', text: '<?php _e('添加分类...'); ?>'},
            {op: 'remove', text: '<?php _e('移除分类...'); ?>'}
        ];
        ops.forEach(function (item) {
            var li = document.createElement('li');
            var a = document.createElement('a');
            a.href = 'javascript:;';
            a.textContent = item.text;
            a.setAttribute('data-cbm-op', item.op);
            a.addEventListener('click', function (e) {
                e.preventDefault();
                var checkboxes = document.querySelectorAll('.typecho-list-table tbody input[name="cid[]"]:checked');
                var cids = [];
                for (var i = 0; i < checkboxes.length; i++) {
                    cids.push(checkboxes[i].value);
                }
                if (!cids.length) {
                    alert('<?php _e('请先选择要操作的文章'); ?>');
                    return;
                }
                openModal(item.op, cids);
            });
            li.appendChild(a);
            menu.appendChild(li);
        });
    }

    // 为每一行的分类单元格增加快速修改入口
    var colIndexInit = getCategoryIndex();
    if (colIndexInit != null) {
        var rows = document.querySelectorAll('.typecho-list-table tbody tr');
        for (var r = 0; r < rows.length; r++) {
            var tds = rows[r].getElementsByTagName('td');
            if (!tds || tds.length <= colIndexInit) {
                continue;
            }
            var cell = tds[colIndexInit];
            var checkbox = rows[r].querySelector('input[name="cid[]"]');
            if (!checkbox) {
                continue;
            }
            var cid = checkbox.value;
            var link = document.createElement('a');
            link.href = 'javascript:;';
            link.className = 'cbm-quick-link';
            link.textContent = '<?php _e('分类'); ?>';
            link.setAttribute('data-cbm-op', 'single');
            link.setAttribute('data-cid', cid);
            link.addEventListener('click', function (e) {
                e.preventDefault();
                var cid = this.getAttribute('data-cid');
                var checkbox = document.querySelector('.typecho-list-table tbody input[name="cid[]"][value="' + cid + '"]');
                if (!checkbox) {
                    return;
                }
                var tr = checkbox.closest('tr');
                if (!tr) {
                    return;
                }
                var tds = tr.getElementsByTagName('td');
                if (!tds || tds.length <= colIndexInit) {
                    return;
                }
                var cell = tds[colIndexInit];
                var links = cell.querySelectorAll('a[href*="manage-posts.php?category="]');
                var mids = [];
                for (var i = 0; i < links.length; i++) {
                    var href = links[i].getAttribute('href') || '';
                    var match = href.match(/category=(\d+)/);
                    if (match) {
                        mids.push(match[1]);
                    }
                }
                openModal('move', [cid], mids);
            });
            cell.appendChild(document.createTextNode(' '));
            cell.appendChild(link);
        }
    }
})();
</script>
<?php
    }
}
