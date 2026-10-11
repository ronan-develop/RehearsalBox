<?php

declare(strict_types=1);

namespace App\Account\Service\Throttle;

/**
 * Blocage de la connexion (#218, #236, #441), PAR ADRESSE IP, par COUPLE (adresse, identifiant saisi) et par IDENTIFIANT SAISI. Aucun
 * ne regarde un compte : un compte réel et une adresse inventée ont exactement le même compteur, donc le même message et la même durée
 * annoncée (rien à énumérer). Le couple (limite basse) arrête celui qui s'acharne SANS bloquer la personne dont il vise l'adresse
 * e-mail, qui se connecte depuis la sienne ; la limite par identifiant (haute) n'arrête qu'une attaque répartie sur de nombreuses
 * adresses. L'identifiant est normalisé (espaces et casse) pour qu'on ne contourne pas la limite en changeant de casse ; rien n'est
 * jamais stocké en clair (empreinte propre à l'application, voir SubjectThrottle). Le verrou du compte (AuthService, seuil haut) reste.
 */
final class LoginThrottle
{
    public function __construct(
        private readonly SubjectThrottle $byAddress,
        private readonly SubjectThrottle $byIdentifier,
        private readonly SubjectThrottle $byPair,
    ) {
    }

    /** Secondes à attendre avant de pouvoir réessayer (0 : pas de blocage) : la plus longue des deux limites. */
    public function remainingSeconds(string $ip, string $email, \DateTimeImmutable $now): int
    {
        return max(
            $this->byAddress->remainingSeconds($ip, $now),
            $this->byIdentifier->remainingSeconds(self::identifier($email), $now),
            $this->byPair->remainingSeconds(self::pair($ip, $email), $now),
        );
    }

    public function recordFailure(string $ip, string $email, \DateTimeImmutable $now): void
    {
        $this->byAddress->record($ip, $now);
        $this->byIdentifier->record(self::identifier($email), $now);
        // Le couple est rattaché à l'identifiant : un déblocage par un administrateur (qui ne connaît pas l'adresse) lève aussi le couple.
        $this->byPair->record(self::pair($ip, $email), $now, $this->byIdentifier->subjectHash(self::identifier($email)));
    }

    /**
     * Connexion réussie ou déblocage par un administrateur : les compteurs de cet identifiant repartent de zéro (celui de l'adresse
     * reste). `$ip` : l'adresse de la connexion réussie, dont le couple repart aussi de zéro ; sans elle (déblocage), les couples
     * expirent seuls avec leur fenêtre.
     */
    public function forgetIdentifier(string $email, string $ip = ''): void
    {
        $this->byIdentifier->forget(self::identifier($email));
        $this->byPair->forget(self::pair($ip, $email));
    }

    /** Vide si l'adresse ou l'identifiant manque : un couple incomplet n'est jamais compté. */
    private static function pair(string $ip, string $email): string
    {
        $identifier = self::identifier($email);

        return $ip === '' || $identifier === '' ? '' : $ip . '|' . $identifier;
    }

    private static function identifier(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
