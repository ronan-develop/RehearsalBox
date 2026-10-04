<?php
/**
 * Modale « nouvelle conversation » avec un groupe (#153), partagée par le dashboard et l'espace groupe.
 * Le serveur revérifie que la personne appartient au groupe émetteur choisi.
 *
 * @var list<array{id: int, name: string}> $userGroups groupes de la personne connectée (vide si anonyme)
 */
?>
<div class="rb-modal-overlay" data-contact-modal-overlay hidden>
    <div class="rb-modal rb-card" role="dialog" aria-modal="true" aria-labelledby="contact-modal-title">
        <h2 id="contact-modal-title" data-contact-modal-title>Nouvelle conversation</h2>
        <form data-contact-form>
            <input type="hidden" name="targetGroupId" data-contact-group-id-input>
            <?php // Visible seulement si la personne peut écrire au nom de plusieurs groupes (cf. contact.js). ?>
            <div class="rb-field" data-contact-from-field data-groups="<?= e((string) json_encode($userGroups ?? [], JSON_UNESCAPED_UNICODE)) ?>" hidden>
                <label for="contact-from">Écrire en tant que</label>
                <select id="contact-from" name="fromGroupId" class="rb-input" data-contact-from-select></select>
            </div>
            <div class="rb-field">
                <label for="contact-subject">Sujet</label>
                <input type="text" id="contact-subject" name="subject" class="rb-input" maxlength="150" required>
            </div>
            <div class="rb-field">
                <label for="contact-message">Message</label>
                <textarea id="contact-message" name="message" class="rb-input" rows="4" maxlength="5000" required></textarea>
            </div>
            <div class="rb-modal-actions">
                <button type="button" class="rb-btn" data-contact-modal-cancel>Annuler</button>
                <button type="submit" class="rb-btn rb-btn-primary">Envoyer</button>
            </div>
        </form>
    </div>
</div>
