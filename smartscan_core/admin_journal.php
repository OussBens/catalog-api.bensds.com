<?php
/** Tableau du journal des scans (inclus par admin_smartscan.php, variable $lignes). */
?>
<table><tr><th>#</th><th>Date</th><th>Client</th><th>Poste</th><th>Statut</th><th>Durée</th><th>Modèle</th><th>Analyses</th><th>Erreur</th></tr>
<?php foreach ($lignes as $s): ?>
  <tr><td><?= (int)$s['id'] ?></td><td><?= dateHeureFr($s['cree_le']) ?></td>
    <td><a href="<?= lien(['page' => 'client', 'id' => $s['client_id']]) ?>"><?= h($s['client_nom']) ?></a></td>
    <td><code><?= h(substr($s['device_id'], 0, 10)) ?>…</code></td>
    <td><span class="pill <?= $s['statut'] === 'reussi' ? 'vert' : ($s['statut'] === 'echoue' ? 'rouge' : 'orange') ?>"><?= h($s['statut']) ?></span></td>
    <td><?= $s['duree_ms'] !== null ? round($s['duree_ms'] / 1000, 1) . ' s' : '—' ?></td><td><?= h($s['modele']) ?></td><td><?= (int)$s['extractions'] ?></td>
    <td><?= h(trim(($s['code_erreur'] ?? '') . ' ' . ($s['erreur_extraction'] ? 'analyse : ' . $s['erreur_extraction'] : ''))) ?>
      <?php if ($s['message_erreur']): ?><br><small style="color:#858585"><?= h($s['message_erreur']) ?></small><?php endif; ?></td></tr>
<?php endforeach; ?>
</table>
