<?php

declare(strict_types=1);

require_once __DIR__ . '/../Support/DatabaseTestCase.php';

/**
 * Table des identités externes (migration 062).
 *
 * Ce que ces tests protègent : un même compte doit pouvoir porter plusieurs
 * fournisseurs — ce que l'ancienne colonne `users.google_id`, UNIQUE, rendait
 * structurellement impossible — et un sujet ne doit jamais être revendiqué par
 * deux comptes.
 */
final class SsoIdentityTest extends DatabaseTestCase
{
    public function testUneIdentiteRattacheUnCompte(): void
    {
        $userId = $this->insertUser(1);

        UserIdentity::link($userId, [
            'provider' => UserIdentity::GOOGLE,
            'subject'  => 'sub-google-1',
            'email'    => 'user1@test.local',
        ]);

        $trouve = UserIdentity::findUser(UserIdentity::GOOGLE, 'sub-google-1');
        $this->assertNotNull($trouve);
        $this->assertSame($userId, (int)$trouve['id']);
    }

    public function testUnSujetInconnuNeTrouveRien(): void
    {
        $this->assertNull(UserIdentity::findUser(UserIdentity::GOOGLE, 'jamais-vu'));
        $this->assertNull(UserIdentity::findUser('', ''));
    }

    public function testUnMemeCompteSeConnecteParDeuxFournisseurs(): void
    {
        // C'est l'exigence qui a imposé la table : `google_id` étant UNIQUE,
        // elle ne pouvait porter qu'un fournisseur par compte.
        $userId = $this->insertUser(2);

        UserIdentity::link($userId, ['provider' => UserIdentity::GOOGLE,    'subject' => 'g-2', 'email' => 'u2@test.local']);
        UserIdentity::link($userId, ['provider' => UserIdentity::MICROSOFT, 'subject' => 'tid.oid-2', 'email' => 'u2@test.local']);

        $this->assertSame($userId, (int)UserIdentity::findUser(UserIdentity::GOOGLE, 'g-2')['id']);
        $this->assertSame($userId, (int)UserIdentity::findUser(UserIdentity::MICROSOFT, 'tid.oid-2')['id']);
        $this->assertSame(
            [UserIdentity::GOOGLE, UserIdentity::MICROSOFT],
            UserIdentity::providersForUser($userId)
        );
    }

    public function testUneReconnexionNeCreePasDeDoublon(): void
    {
        $userId = $this->insertUser(3);
        $identite = ['provider' => UserIdentity::GOOGLE, 'subject' => 'g-3', 'email' => 'u3@test.local'];

        UserIdentity::link($userId, $identite);
        UserIdentity::link($userId, $identite);

        $stmt = $this->db()->prepare('SELECT COUNT(*) FROM user_identities WHERE user_id = ?');
        $stmt->execute([$userId]);
        $this->assertSame(1, (int)$stmt->fetchColumn());
    }

    public function testLeMiroirLegacyNeVautQuePourGoogle(): void
    {
        // `users.google_id` reste renseignée pour Google seulement : un retour
        // arrière du code la retrouverait, et l'accès de secours s'y ancre.
        $google    = $this->insertUserSansIdentifiant(10);
        $microsoft = $this->insertUserSansIdentifiant(11);

        UserIdentity::link($google, ['provider' => UserIdentity::GOOGLE, 'subject' => 'g-10', 'email' => '']);
        UserIdentity::link($microsoft, ['provider' => UserIdentity::MICROSOFT, 'subject' => 'ms-11', 'email' => '']);

        $this->assertSame('g-10', $this->googleIdDe($google));
        $this->assertNull($this->googleIdDe($microsoft), 'Un compte Microsoft n\'a pas à s\'inventer un identifiant Google.');
    }

    public function testSupprimerUnCompteSupprimeSesIdentites(): void
    {
        $userId = $this->insertUser(4);
        UserIdentity::link($userId, ['provider' => UserIdentity::GOOGLE, 'subject' => 'g-4', 'email' => '']);

        User::delete($userId);

        $this->assertNull(
            UserIdentity::findUser(UserIdentity::GOOGLE, 'g-4'),
            'Sans la cascade, un sujet resterait revendiqué par un compte disparu.'
        );
    }

    public function testLesFournisseursSeLisentEnUneRequetePourToutePage(): void
    {
        $a = $this->insertUser(5);
        $b = $this->insertUser(6);
        $this->insertUser(7); // aucun fournisseur

        UserIdentity::link($a, ['provider' => UserIdentity::GOOGLE, 'subject' => 'g-5', 'email' => '']);
        UserIdentity::link($b, ['provider' => UserIdentity::MICROSOFT, 'subject' => 'ms-6', 'email' => '']);

        $parUtilisateur = UserIdentity::providersForUsers([$a, $b, 7]);

        $this->assertSame([UserIdentity::GOOGLE], $parUtilisateur[$a]);
        $this->assertSame([UserIdentity::MICROSOFT], $parUtilisateur[$b]);
        $this->assertArrayNotHasKey(7, $parUtilisateur);
    }

    public function testLeLibelleDecritTousLesModesDeConnexion(): void
    {
        $this->assertSame('Google', UserIdentity::authLabel([UserIdentity::GOOGLE], false));
        $this->assertSame('Microsoft', UserIdentity::authLabel([UserIdentity::MICROSOFT], false));
        $this->assertSame(
            'Google + Mot de passe',
            UserIdentity::authLabel([UserIdentity::GOOGLE], true),
            'Un compte peut porter les deux : le libellé ne doit pas en cacher un.'
        );
        $this->assertSame('Admin local', UserIdentity::authLabel([], false, true));
        $this->assertSame('', UserIdentity::authLabel([], false));
    }

    public function testUnCompteCreeParSsoNaPasDeMotDePasseNiDIdentifiantSynthetique(): void
    {
        $user = User::createFromSso('nouveau@test.local', 'Nouveau', '');

        $this->assertNotNull($user);
        $this->assertNull($user['password_hash']);
        $this->assertNull($user['google_id'], 'La colonne legacy ne doit plus recevoir de valeur inventée.');
        $this->assertSame('admin', $user['role'], 'Le tout premier compte est administrateur, comme /setup.');
        $this->assertSame(1, (int)$user['is_active']);
    }

    public function testUnCompteExistantSeRetrouveParEmailQuelQueSoitSonModeDeConnexion(): void
    {
        // findByEmail() filtre sur `password_hash IS NOT NULL` : un compte
        // créé par SSO y serait invisible, et le rapprochement échouerait en
        // heurtant l'index unique sur l'e-mail.
        User::createFromSso('sso@test.local', 'SSO', '');

        $this->assertNull(User::findByEmail('sso@test.local'));
        $this->assertNotNull(User::findByEmailAnyType('sso@test.local'));
    }

    // ---- Utilitaires -------------------------------------------------------

    private function insertUserSansIdentifiant(int $id): int
    {
        $this->db()->prepare(
            'INSERT INTO users (id, google_id, email, name, role, is_active) VALUES (?, NULL, ?, ?, ?, 1)'
        )->execute([$id, "user{$id}@test.local", "User {$id}", 'user']);
        return $id;
    }

    private function googleIdDe(int $userId): ?string
    {
        $stmt = $this->db()->prepare('SELECT google_id FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $valeur = $stmt->fetchColumn();
        return $valeur === false || $valeur === null ? null : (string)$valeur;
    }
}
