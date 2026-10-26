<?php

declare(strict_types=1);

class ProfileController
{
    public function index(): void
    {
        AuthMiddleware::requireAuth();
        AuthMiddleware::refreshUser();
        $user     = Session::currentUser();
        $groupScope = UserGroup::membershipScopeForUser((int)$user['id']);
        $profiles = Profile::accessibleByUser(
            (int)$user['id'],
            (string)$user['role'],
            $groupScope['active_group_ids'],
            $groupScope['has_assignments']
        );
        header('Content-Type: application/json');
        echo json_encode($profiles);
    }
}
