<?php
declare(strict_types=1);

namespace Ascs\Web\Controllers;

use Ascs\Domain\Cautions;
use Ascs\Web\App;

/** Chèques de caution en attente d'espèces et rappel automatique par mail avant la date de pose. */
final class CautionController
{
    private Cautions $cau;

    public function __construct(private readonly App $app)
    {
        $this->cau = new Cautions($app->pdo);
    }

    public function index(array $p): string
    {
        $dus = $this->cau->aRappeler();
        return $this->app->render('cautions/index', [
            'liste' => $this->cau->enAttente(), 'reglages' => $this->cau->reglages(), 'dus' => $dus,
            'exemple' => $dus ? $this->cau->message($dus[0]) : null,
        ], 'Chèques de caution');
    }

    public function enregistrer(array $p): void
    {
        try {
            $this->cau->enregistrer(!empty($_POST['actif']), (int) ($_POST['jours'] ?? 0),
                (string) ($_POST['objet'] ?? ''), str_replace("\r\n", "\n", (string) ($_POST['texte'] ?? '')));
            $this->app->flash('ok', !empty($_POST['actif'])
                ? 'Enregistré. Les rappels partiront chaque jour à partir de 9 h.'
                : 'Enregistré. Les rappels automatiques sont désactivés.');
        } catch (\RuntimeException $e) {
            $this->app->flash('erreur', $e->getMessage());
        }
        $this->app->redirect('/cautions');
    }

    public function envoyer(array $p): void
    {
        $b = $this->cau->envoyerRappels((string) ($this->app->user['email'] ?? ''), true);
        if (!$b['envoyes'] && !$b['echecs']) {
            $this->app->flash('ok', 'Aucun rappel à envoyer aujourd\'hui.');
        } else {
            $this->app->flash($b['echecs'] ? 'erreur' : 'ok', $b['envoyes'] . ' rappel(s) envoyé(s)'
                . ($b['echecs'] ? ', ' . $b['echecs'] . ' échec(s) : ' . implode(' ; ', $b['erreurs']) : '') . '.');
        }
        $this->app->redirect('/cautions');
    }
}
