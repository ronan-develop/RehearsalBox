<?php
/**
 * Fenêtre de confirmation (#326) : à poser UNE fois par page qui demande de confirmer une action. Le HTML vient du serveur, le
 * composant <rb-confirm-dialog> y met seulement le texte (textContent) et ouvre l'élément natif <dialog> : focus, Échap et
 * fermeture sont gérés par le navigateur. Le formulaire `method="dialog"` ne quitte jamais la page ni n'envoie de données.
 * Le focus initial est sur « Annuler » : Entrée ne détruit rien par réflexe.
 */
?>
<rb-confirm-dialog>
    <dialog class="rb-modal rb-card" role="alertdialog" aria-labelledby="rb-confirm-title" aria-describedby="rb-confirm-body">
        <h2 class="rb-modal-title" id="rb-confirm-title" data-confirm-title hidden></h2>
        <div id="rb-confirm-body" data-confirm-body></div>
        <form method="dialog" class="rb-modal-actions">
            <button type="submit" value="cancel" class="rb-btn rb-modal-cancel" data-confirm-cancel autofocus>Annuler</button>
            <button type="submit" value="confirm" class="rb-btn rb-modal-confirm" data-confirm-accept>Confirmer</button>
        </form>
    </dialog>
</rb-confirm-dialog>
