<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Response;
use App\Helpers\Database;
use App\Middleware\AdminMiddleware;

class AdminCmsController
{
    // =========================================================================
    // Admin: GET /api/admin/cms/pages
    // =========================================================================

    public function index(Request $request): void
    {
        $db   = Database::getInstance();
        $stmt = $db->query(
            'SELECT id, slug, title_en, title_ar, is_published, created_at, updated_at
             FROM cms_pages
             ORDER BY id ASC'
        );
        $pages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json(['data' => $pages]);
    }

    // =========================================================================
    // Admin: POST /api/admin/cms/pages
    // =========================================================================

    public function store(Request $request): void
    {
        $errors = $request->validate([
            'slug'       => 'required',
            'title_en'   => 'required',
            'title_ar'   => 'required',
            'content_en' => 'required',
            'content_ar' => 'required',
        ]);

        if (!empty($errors)) {
            Response::validationError($errors);
        }

        $admin = AdminMiddleware::currentAdmin();

        $db   = Database::getInstance();
        $slug = (string)$request->input('slug');

        // Check unique slug
        $existing = $db->prepare('SELECT id FROM cms_pages WHERE slug = ?');
        $existing->execute([$slug]);
        if ($existing->fetchColumn()) {
            Response::error('A page with this slug already exists.', 409, 'slug_conflict');
        }

        $stmt = $db->prepare(
            'INSERT INTO cms_pages (slug, title_en, title_ar, content_en, content_ar,
                meta_title_en, meta_desc_en, meta_title_ar, meta_desc_ar,
                is_published, published_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );

        $isPublished = (int)(bool)$request->input('is_published');
        $stmt->execute([
            $slug,
            (string)$request->input('title_en'),
            (string)$request->input('title_ar'),
            (string)$request->input('content_en'),
            (string)$request->input('content_ar'),
            $request->input('meta_title_en'),
            $request->input('meta_description_en') ?? $request->input('meta_desc_en'),
            $request->input('meta_title_ar'),
            $request->input('meta_description_ar') ?? $request->input('meta_desc_ar'),
            $isPublished,
            $isPublished ? date('Y-m-d H:i:s') : null,
        ]);

        $id   = (int)$db->lastInsertId();
        $page = $this->findById($db, $id);

        Response::created(['data' => $page]);
    }

    // =========================================================================
    // Admin: GET /api/admin/cms/pages/:id
    // =========================================================================

    public function show(Request $request): void
    {
        $id   = (int)($request->param('id') ?? 0);
        $page = $this->findById(Database::getInstance(), $id);

        if (!$page) {
            Response::error('Page not found.', 404, 'not_found');
        }

        Response::json(['data' => $page]);
    }

    // =========================================================================
    // Admin: PUT /api/admin/cms/pages/:id
    // =========================================================================

    public function update(Request $request): void
    {
        $id = (int)($request->param('id') ?? 0);
        $db = Database::getInstance();

        $page = $this->findById($db, $id);
        if (!$page) {
            Response::error('Page not found.', 404, 'not_found');
        }

        $slug = $request->input('slug') ?? $page['slug'];

        // Check slug uniqueness (excluding self)
        $existing = $db->prepare('SELECT id FROM cms_pages WHERE slug = ? AND id != ?');
        $existing->execute([$slug, $id]);
        if ($existing->fetchColumn()) {
            Response::error('A page with this slug already exists.', 409, 'slug_conflict');
        }

        $isPublishedInput = $request->input('is_published');
        $isPublished = $isPublishedInput !== null
            ? (int)(bool)$isPublishedInput
            : (int)$page['is_published'];

        $stmt = $db->prepare(
            'UPDATE cms_pages SET
                slug = ?, title_en = ?, title_ar = ?,
                content_en = ?, content_ar = ?,
                meta_title_en = ?, meta_desc_en = ?,
                meta_title_ar = ?, meta_desc_ar = ?,
                is_published = ?,
                published_at = CASE WHEN ? = 1 AND published_at IS NULL THEN NOW() ELSE published_at END,
                updated_at = NOW()
             WHERE id = ?'
        );

        $stmt->execute([
            $slug,
            $request->input('title_en') ?? $page['title_en'],
            $request->input('title_ar') ?? $page['title_ar'],
            $request->input('content_en') ?? $page['content_en'],
            $request->input('content_ar') ?? $page['content_ar'],
            $request->input('meta_title_en') ?? $page['meta_title_en'],
            $request->input('meta_description_en') ?? $request->input('meta_desc_en') ?? $page['meta_desc_en'],
            $request->input('meta_title_ar') ?? $page['meta_title_ar'],
            $request->input('meta_description_ar') ?? $request->input('meta_desc_ar') ?? $page['meta_desc_ar'],
            $isPublished,
            $isPublished,
            $id,
        ]);

        Response::json(['data' => $this->findById($db, $id)]);
    }

    // =========================================================================
    // Admin: DELETE /api/admin/cms/pages/:id
    // =========================================================================

    public function destroy(Request $request): void
    {
        $id = (int)($request->param('id') ?? 0);
        $db = Database::getInstance();

        $page = $this->findById($db, $id);
        if (!$page) {
            Response::error('Page not found.', 404, 'not_found');
        }

        $db->prepare('DELETE FROM cms_pages WHERE id = ?')->execute([$id]);

        Response::noContent();
    }

    // =========================================================================
    // Public: GET /api/cms/pages
    // =========================================================================

    public function publicList(Request $request): void
    {
        $db   = Database::getInstance();
        $stmt = $db->query(
            'SELECT id, slug, title_en, title_ar
             FROM cms_pages
             WHERE is_published = 1
             ORDER BY id ASC'
        );

        Response::json(['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    // =========================================================================
    // Public: GET /api/cms/pages/:slug
    // =========================================================================

    public function publicShow(Request $request): void
    {
        $slug = $request->param('slug') ?? '';

        if ($slug === '') {
            Response::error('Slug is required.', 422);
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare(
            'SELECT id, slug, title_en, title_ar, content_en, content_ar,
                    meta_title_en, meta_desc_en, meta_title_ar, meta_desc_ar,
                    published_at, updated_at
             FROM cms_pages
             WHERE slug = ? AND is_published = 1
             LIMIT 1'
        );
        $stmt->execute([$slug]);
        $page = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$page) {
            Response::error('Page not found.', 404, 'not_found');
        }

        Response::json(['data' => $page]);
    }

    // =========================================================================
    // Internal helpers
    // =========================================================================

    private function findById(\PDO $db, int $id): array|false
    {
        if ($id <= 0) {
            return false;
        }
        $stmt = $db->prepare('SELECT * FROM cms_pages WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }
}
