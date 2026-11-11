<?php
declare(strict_types=1);

/** 카테고리 / 서비스 / 프로젝트 / 지원(입찰) 관련 API */
final class MarketController
{
    public static function categories(): void
    {
        $rows = db()->query(
            'SELECT id, parent_id, name, slug FROM categories WHERE is_active = 1 ORDER BY sort_order, id'
        )->fetchAll();
        json_response(['categories' => $rows]);
    }

    // ---- 서비스 -----------------------------------------------------------

    public static function listServices(): void
    {
        $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;
        $q = trim((string)($_GET['q'] ?? ''));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $where = ["s.status = 'active'"];
        $params = [];
        // 내가 등록한 서비스(마이페이지): 상태 무관, 본인 것만
        if (($_GET['mine'] ?? '') === '1') {
            $me = require_login();
            $where = ['s.freelancer_id = ?'];
            $params = [$me['id']];
        }
        if ($categoryId) {
            $where[] = 's.category_id = ?';
            $params[] = $categoryId;
        }
        if ($q !== '') {
            $where[] = '(s.title LIKE ? OR s.description LIKE ?)';
            $params[] = "%$q%";
            $params[] = "%$q%";
        }
        $sql = 'SELECT s.id, s.title, s.price, s.delivery_days, s.category_id,
                       u.id AS freelancer_id, u.name AS freelancer_name,
                       fp.rating_avg, fp.completed_orders
                FROM services s
                JOIN users u ON u.id = s.freelancer_id
                LEFT JOIN freelancer_profiles fp ON fp.user_id = s.freelancer_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY fp.rating_avg DESC, s.id DESC
                LIMIT ' . $limit . ' OFFSET ' . $offset;

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        json_response(['services' => $stmt->fetchAll(), 'limit' => $limit, 'offset' => $offset]);
    }

    public static function showService(string $id): void
    {
        $stmt = db()->prepare(
            'SELECT s.*, u.name AS freelancer_name, fp.headline, fp.rating_avg
             FROM services s
             JOIN users u ON u.id = s.freelancer_id
             LEFT JOIN freelancer_profiles fp ON fp.user_id = s.freelancer_id
             WHERE s.id = ? LIMIT 1'
        );
        $stmt->execute([(int)$id]);
        $service = $stmt->fetch();
        if (!$service) {
            json_error('서비스를 찾을 수 없습니다.', 404);
        }
        json_response(['service' => $service]);
    }

    public static function createService(): void
    {
        $user = require_role('freelancer');
        $data = json_input();
        require_fields($data, ['title', 'price']);

        $price = (float)$data['price'];
        if ($price <= 0) {
            json_error('가격은 0보다 커야 합니다.', 422);
        }

        $stmt = db()->prepare(
            'INSERT INTO services (freelancer_id, category_id, title, description, price, delivery_days, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $user['id'],
            $data['category_id'] ?? null,
            trim((string)$data['title']),
            $data['description'] ?? null,
            money($price),
            (int)($data['delivery_days'] ?? 3),
            'active',
        ]);
        json_response(['service_id' => (int)db()->lastInsertId()], 201);
    }

    // ---- 프로젝트 ---------------------------------------------------------

    public static function listProjects(): void
    {
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));

        $where = [];
        $params = [];
        // 내가 등록한 프로젝트(마이페이지): 상태 무관
        if (($_GET['mine'] ?? '') === '1') {
            $me = require_login();
            $where[] = 'p.client_id = ?';
            $params[] = $me['id'];
        } else {
            $where[] = 'p.status = ?';
            $params[] = $_GET['status'] ?? 'open';
        }

        $stmt = db()->prepare(
            'SELECT p.id, p.title, p.budget, p.deadline, p.status, p.category_id, p.created_at,
                    u.name AS client_name,
                    (SELECT COUNT(*) FROM proposals pr WHERE pr.project_id = p.id) AS proposal_count
             FROM projects p
             JOIN users u ON u.id = p.client_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY p.id DESC
             LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        $stmt->execute($params);
        json_response(['projects' => $stmt->fetchAll()]);
    }

    public static function createProject(): void
    {
        $user = require_role('client');
        $data = json_input();
        require_fields($data, ['title']);

        $stmt = db()->prepare(
            'INSERT INTO projects (client_id, category_id, title, description, budget, deadline, attachment)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $user['id'],
            $data['category_id'] ?? null,
            trim((string)$data['title']),
            $data['description'] ?? null,
            isset($data['budget']) ? money((float)$data['budget']) : null,
            $data['deadline'] ?? null,
            $data['attachment'] ?? null,
        ]);
        json_response(['project_id' => (int)db()->lastInsertId()], 201);
    }

    public static function showProject(string $id): void
    {
        $pid = (int)$id;
        $stmt = db()->prepare(
            'SELECT p.*, u.name AS client_name FROM projects p
             JOIN users u ON u.id = p.client_id WHERE p.id = ? LIMIT 1'
        );
        $stmt->execute([$pid]);
        $project = $stmt->fetch();
        if (!$project) {
            json_error('프로젝트를 찾을 수 없습니다.', 404);
        }

        $proposals = db()->prepare(
            'SELECT pr.id, pr.freelancer_id, pr.price, pr.delivery_days, pr.message, pr.status, pr.created_at,
                    u.name AS freelancer_name, fp.rating_avg
             FROM proposals pr
             JOIN users u ON u.id = pr.freelancer_id
             LEFT JOIN freelancer_profiles fp ON fp.user_id = pr.freelancer_id
             WHERE pr.project_id = ? ORDER BY pr.id DESC'
        );
        $proposals->execute([$pid]);

        json_response(['project' => $project, 'proposals' => $proposals->fetchAll()]);
    }

    /** 프리랜서가 프로젝트에 지원 */
    public static function submitProposal(string $projectId): void
    {
        $user = require_role('freelancer');
        $pid = (int)$projectId;
        $data = json_input();
        require_fields($data, ['price']);

        $stmt = db()->prepare('SELECT status, client_id, title FROM projects WHERE id = ? LIMIT 1');
        $stmt->execute([$pid]);
        $project = $stmt->fetch();
        if (!$project) {
            json_error('프로젝트를 찾을 수 없습니다.', 404);
        }
        if ($project['status'] !== 'open') {
            json_error('이미 마감된 프로젝트입니다.', 409);
        }

        $price = (float)$data['price'];
        if ($price <= 0) {
            json_error('제안 금액은 0보다 커야 합니다.', 422);
        }

        $stmt = db()->prepare(
            'INSERT INTO proposals (project_id, freelancer_id, price, delivery_days, message)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE price = VALUES(price), delivery_days = VALUES(delivery_days), message = VALUES(message)'
        );
        $stmt->execute([
            $pid,
            $user['id'],
            money($price),
            (int)($data['delivery_days'] ?? 3),
            $data['message'] ?? null,
        ]);

        NotificationService::notify((int)$project['client_id'], 'proposal.submitted', '새 지원이 도착했습니다',
            "프로젝트 '{$project['title']}'에 새로운 지원이 등록되었습니다.", '/projects/' . $pid, 'project', $pid);
        json_response(['message' => '지원이 완료되었습니다.'], 201);
    }

    /**
     * 의뢰인이 특정 지원(입찰)을 수락 → 주문(에스크로 대기) 생성
     * 이후 클라이언트는 /orders/{id}/pay 로 결제한다.
     */
    public static function acceptProposal(string $proposalId): void
    {
        $user = require_role('client');
        $prId = (int)$proposalId;

        $orderNo = db_transaction(function (PDO $pdo) use ($prId, $user): string {
            $stmt = $pdo->prepare(
                'SELECT pr.*, p.client_id, p.title
                 FROM proposals pr
                 JOIN projects p ON p.id = pr.project_id
                 WHERE pr.id = ? FOR UPDATE'
            );
            $stmt->execute([$prId]);
            $proposal = $stmt->fetch();
            if (!$proposal) {
                throw new DomainException('지원 내역을 찾을 수 없습니다.');
            }
            if ((int)$proposal['client_id'] !== (int)$user['id']) {
                throw new DomainException('본인의 프로젝트에 대한 지원만 수락할 수 있습니다.');
            }
            if ($proposal['status'] === 'accepted') {
                throw new DomainException('이미 수락된 지원입니다.');
            }

            $pdo->prepare("UPDATE proposals SET status = 'accepted' WHERE id = ?")->execute([$prId]);
            $pdo->prepare("UPDATE proposals SET status = 'rejected' WHERE project_id = ? AND id <> ? AND status = 'submitted'")
                ->execute([$proposal['project_id'], $prId]);
            $pdo->prepare("UPDATE projects SET status = 'in_progress' WHERE id = ?")
                ->execute([$proposal['project_id']]);

            $orderNo = generate_order_no();
            $pdo->prepare(
                'INSERT INTO orders
                   (order_no, project_id, proposal_id, client_id, freelancer_id, title,
                    amount, delivery_days, status, escrow_status, due_date)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(CURDATE(), INTERVAL ? DAY))'
            )->execute([
                $orderNo,
                $proposal['project_id'],
                $prId,
                $proposal['client_id'],
                $proposal['freelancer_id'],
                $proposal['title'],
                money((float)$proposal['price']),
                (int)$proposal['delivery_days'],
                'pending_payment',
                'none',
                (int)$proposal['delivery_days'],
            ]);

            return $orderNo;
        });

        audit_log('order.created', 'order', null, ['order_no' => $orderNo]);
        $row = db()->prepare('SELECT id, freelancer_id FROM orders WHERE order_no = ? LIMIT 1');
        $row->execute([$orderNo]);
        if ($o = $row->fetch()) {
            NotificationService::notify((int)$o['freelancer_id'], 'proposal.accepted', '지원이 수락되었습니다',
                '프로젝트 지원이 수락되어 주문이 생성되었습니다.', '/orders/' . (int)$o['id'], 'order', (int)$o['id']);
        }
        json_response(['message' => '지원을 수락했습니다. 결제를 진행해 주세요.', 'order_no' => $orderNo], 201);
    }
}
