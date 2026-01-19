<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

use Typecho_Db;
use Typecho_Widget;

/**
 * CategoryBatchManager Action
 *
 * 处理批量分类操作的后端逻辑
 */
class CategoryBatchManager_Action extends Typecho_Widget implements Widget_Interface_Do
{
    /** @var Typecho_Db */
    private $db;

    public function execute()
    {
    }

    /**
     * 入口
     *
     * @throws Typecho_Widget_Exception
     */
    public function action()
    {
        $this->db = Typecho_Db::get();

        // 权限检查：必须登录且具备编辑以上权限
        /** @var Widget_User $user */
        $user = Typecho_Widget::widget('Widget_User');
        if (!$user->hasLogin() || !$user->pass('editor', true)) {
            throw new Typecho_Widget_Exception('权限不足', 403);
        }

        $this->on($this->request->is('do=batch'))->batch();

        // 如果没有匹配的操作，返回 400
        if (!$this->request->is('do=batch')) {
            $this->response->throwJson([
                'success' => false,
                'message' => '无效的操作',
            ]);
        }
    }

    /**
     * 批量更新分类
     */
    public function batch()
    {
        if (!$this->request->isPost()) {
            $this->response->throwJson([
                'success' => false,
                'message' => '仅支持 POST 请求',
            ]);
        }

        $op = $this->request->get('op');
        $cids = $this->request->filter('int')->getArray('cid');
        $mids = $this->request->filter('int')->getArray('mid');

        if (!in_array($op, ['move', 'add', 'remove'], true)) {
            $this->response->throwJson([
                'success' => false,
                'message' => '未知操作类型',
            ]);
        }

        if (empty($cids) || empty($mids)) {
            $this->response->throwJson([
                'success' => false,
                'message' => '缺少文章或分类参数',
            ]);
        }

        $cids = array_unique(array_filter($cids));
        $mids = array_unique(array_filter($mids));

        if (empty($cids) || empty($mids)) {
            $this->response->throwJson([
                'success' => false,
                'message' => '参数不合法',
            ]);
        }

        // 校验分类是否存在且为 category 类型
        $validMids = $this->getValidCategoryMids($mids);
        if (empty($validMids)) {
            $this->response->throwJson([
                'success' => false,
                'message' => '未找到有效的分类',
            ]);
        }

        $db = $this->db;

        try {
            foreach ($cids as $cid) {
                if (!$cid) {
                    continue;
                }

                if ('move' === $op) {
                    // 先移除该文章所有分类关系
                    $this->deleteAllCategoriesOfPost($cid);

                    // 再统一插入新的分类
                    foreach ($validMids as $mid) {
                        $this->insertRelationIfNotExists($cid, $mid);
                    }
                } elseif ('add' === $op) {
                    foreach ($validMids as $mid) {
                        $this->insertRelationIfNotExists($cid, $mid);
                    }
                } elseif ('remove' === $op) {
                    foreach ($validMids as $mid) {
                        $this->deleteRelationIfExists($cid, $mid);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->response->throwJson([
                'success' => false,
                'message' => '数据库操作失败：' . $e->getMessage(),
            ]);
        }

        $this->response->throwJson([
            'success' => true,
        ]);
    }

    /**
     * 获取有效的分类 mid 列表（仅保留 type=category 的项）
     *
     * @param array $mids
     * @return array
     */
    private function getValidCategoryMids(array $mids): array
    {
        if (empty($mids)) {
            return [];
        }

        $rows = $this->db->fetchAll(
            $this->db->select('mid')
                ->from('table.metas')
                ->where('type = ?', 'category')
                ->where('mid IN ?', $mids)
        );

        return array_map(function ($row) {
            return (int)$row['mid'];
        }, $rows);
    }

    /**
     * 删除文章的所有分类关系（不影响标签等其他 metas）
     *
     * @param int $cid
     */
    private function deleteAllCategoriesOfPost(int $cid): void
    {
        // 找出该文章当前所有分类 mid
        $rows = $this->db->fetchAll(
            $this->db->select('table.relationships.mid')
                ->from('table.relationships')
                ->join('table.metas', 'table.metas.mid = table.relationships.mid')
                ->where('table.relationships.cid = ?', $cid)
                ->where('table.metas.type = ?', 'category')
        );

        foreach ($rows as $row) {
            $mid = (int)$row['mid'];
            $this->deleteRelationIfExists($cid, $mid);
        }
    }

    /**
     * 若关系不存在则插入
     *
     * @param int $cid
     * @param int $mid
     */
    private function insertRelationIfNotExists(int $cid, int $mid): void
    {
        $exists = $this->db->fetchRow(
            $this->db->select('cid')
                ->from('table.relationships')
                ->where('cid = ?', $cid)
                ->where('mid = ?', $mid)
        );

        if (!$exists) {
            $this->db->query(
                $this->db->insert('table.relationships')->rows([
                    'cid' => $cid,
                    'mid' => $mid,
                ])
            );
        }
    }

    /**
     * 若关系存在则删除
     *
     * @param int $cid
     * @param int $mid
     */
    private function deleteRelationIfExists(int $cid, int $mid): void
    {
        $this->db->query(
            $this->db->delete('table.relationships')
                ->where('cid = ?', $cid)
                ->where('mid = ?', $mid)
        );
    }
}
