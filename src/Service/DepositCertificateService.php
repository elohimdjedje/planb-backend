<?php

namespace App\Service;

use App\Entity\SecureDeposit;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

/**
 * Génère le certificat PDF « ATTESTATION DE CAUTION SÉCURISÉE ».
 * Utilise Dompdf si installé ; sinon retourne une URL vide.
 *
 * Le document contient :
 *  - Identité bailleur & locataire
 *  - Bien loué
 *  - Détails de la caution (montant, commission, séquestre, transaction)
 *  - Conditions de restitution
 *  - Signatures électroniques
 */
class DepositCertificateService
{
    private string $outputDir;

    public function __construct(
        private ParameterBagInterface $params,
        private LoggerInterface $logger,
    ) {
        $this->outputDir = $params->get('kernel.project_dir') . '/public/uploads/certificates';

        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }

    /**
     * Génère le PDF et retourne l'URL publique.
     */
    public function generate(SecureDeposit $deposit): string
    {
        if (!class_exists(\Dompdf\Dompdf::class)) {
            $this->logger->warning('[Certificate] Dompdf non installé — PDF non généré');
            return '';
        }

        try {
            $html = $this->buildHtml($deposit);

            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $filename = 'caution_' . $deposit->getId() . '_' . time() . '.pdf';
            $filepath = $this->outputDir . '/' . $filename;
            file_put_contents($filepath, $dompdf->output());

            $baseUrl = $_ENV['APP_URL'] ?? 'http://localhost:8000';
            $url     = $baseUrl . '/uploads/certificates/' . $filename;

            $deposit->setCertificatePdfUrl($url);

            $this->logger->info('[Certificate] PDF généré', [
                'deposit_id' => $deposit->getId(),
                'url'        => $url,
            ]);

            return $url;
        } catch (\Exception $e) {
            $this->logger->error('[Certificate] Erreur génération PDF', [
                'deposit_id' => $deposit->getId(),
                'error'      => $e->getMessage(),
            ]);
            return '';
        }
    }

    // ─────────────────────────────────────────────────────────
    private function buildHtml(SecureDeposit $deposit): string
    {
        $tenant   = $deposit->getTenant();
        $landlord = $deposit->getLandlord();
        $listing  = $deposit->getListing();

        $tenantName   = $tenant->getFirstName() . ' ' . $tenant->getLastName();
        $landlordName = $landlord->getFirstName() . ' ' . $landlord->getLastName();

        $tenantPhone   = $tenant->getPhone() ?? '—';
        $landlordPhone = $landlord->getPhone() ?? '—';

        $tenantId   = ($deposit->getTenantIdType() ?? '—') . ' — ' . ($deposit->getTenantIdNumber() ?? '—');
        $landlordId = ($deposit->getLandlordIdType() ?? '—') . ' — ' . ($deposit->getLandlordIdNumber() ?? '—');

        $propertyType = match ($deposit->getPropertyType()) {
            'maison'       => 'Maison',
            'appartement'  => 'Appartement',
            'bureau'       => 'Bureau',
            'vehicule'     => 'Véhicule',
            default        => $deposit->getPropertyType(),
        };

        $amount     = number_format((float) $deposit->getDepositAmount(), 0, ',', ' ');
        $commission = number_format((float) $deposit->getCommissionAmount(), 0, ',', ' ');
        $escrowed   = number_format((float) $deposit->getEscrowedAmount(), 0, ',', ' ');

        $provider    = $deposit->getPaymentProvider() ?? '—';
        $transaction = $deposit->getTransactionId() ?? '—';
        $paidDate    = $deposit->getPaidAt() ? $deposit->getPaidAt()->format('d/m/Y à H:i') : '—';

        $landlordSigned = $deposit->getLandlordSignedAt()
            ? '✔️ Signé le ' . $deposit->getLandlordSignedAt()->format('d/m/Y à H:i')
            : '⏳ En attente';
        $tenantSigned = $deposit->getTenantSignedAt()
            ? '✔️ Signé le ' . $deposit->getTenantSignedAt()->format('d/m/Y à H:i')
            : '⏳ En attente';

        $generatedAt = (new \DateTime())->format('d/m/Y à H:i');

        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Attestation de Caution Sécurisée #{$deposit->getId()}</title>
<style>
    @page { margin: 30px 40px; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 12px; color: #222; line-height: 1.6; }
    .header { text-align: center; margin-bottom: 25px; border-bottom: 3px solid #e67e22; padding-bottom: 15px; }
    .header h1 { font-size: 20px; color: #e67e22; margin: 0 0 5px; }
    .header p { margin: 0; font-size: 11px; color: #666; }
    h2 { font-size: 14px; color: #e67e22; border-bottom: 1px solid #ddd; padding-bottom: 5px; margin-top: 20px; }
    .row { margin: 4px 0; }
    .label { font-weight: bold; display: inline-block; width: 220px; }
    .sig-box { display: inline-block; width: 45%; vertical-align: top; border: 1px solid #ccc; padding: 12px; margin-top: 10px; }
    .sig-box h3 { margin: 0 0 8px; font-size: 13px; color: #333; }
    .legal { margin-top: 30px; font-size: 10px; color: #888; text-align: center; border-top: 1px solid #ddd; padding-top: 10px; }
    .conditions li { margin-bottom: 6px; }
</style>
</head>
<body>

<div class="header">
    <h1>ATTESTATION DE CAUTION SÉCURISÉE</h1>
    <p>Référence : PLANB-ESC-{$deposit->getId()} &nbsp;|&nbsp; Date : {$paidDate}</p>
</div>

<!-- 1. PARTIES -->
<h2>1. Informations des parties</h2>

<p><strong>Bailleur (Propriétaire)</strong></p>
<div class="row"><span class="label">Nom et prénom :</span> {$landlordName}</div>
<div class="row"><span class="label">Numéro de téléphone :</span> {$landlordPhone}</div>
<div class="row"><span class="label">Pièce d'identité :</span> {$landlordId}</div>

<p style="margin-top:12px;"><strong>Locataire</strong></p>
<div class="row"><span class="label">Nom et prénom :</span> {$tenantName}</div>
<div class="row"><span class="label">Numéro de téléphone :</span> {$tenantPhone}</div>
<div class="row"><span class="label">Pièce d'identité :</span> {$tenantId}</div>

<!-- 2. BIEN CONCERNE -->
<h2>2. Bien concerné</h2>
<div class="row"><span class="label">Type de bien :</span> {$propertyType}</div>
<div class="row"><span class="label">Description :</span> {$deposit->getPropertyDescription()}</div>
<div class="row"><span class="label">Adresse ou localisation :</span> {$deposit->getPropertyAddress()}</div>

<!-- 3. DÉTAILS DE LA CAUTION -->
<h2>3. Détails de la caution</h2>
<div class="row"><span class="label">Montant total de la caution :</span> {$amount} FCFA</div>
<div class="row"><span class="label">Commission plateforme (5 %) :</span> {$commission} FCFA</div>
<div class="row"><span class="label">Montant bloqué en séquestre :</span> {$escrowed} FCFA</div>
<div class="row"><span class="label">Prestataire de paiement :</span> {$provider}</div>
<div class="row"><span class="label">Numéro de transaction :</span> {$transaction}</div>
<div class="row"><span class="label">Date de paiement :</span> {$paidDate}</div>

<!-- 4. CONDITIONS -->
<h2>4. Conditions de restitution</h2>
<ul class="conditions">
    <li>La caution est bloquée chez un prestataire de paiement agréé.</li>
    <li>Un délai de <strong>72 heures</strong> après la fin de la location est accordé pour signaler tout dommage.</li>
    <li>Sans réclamation dans ce délai → <strong>remboursement automatique total</strong> au locataire.</li>
    <li>En cas de dommage : un devis est requis ; le remboursement peut être partiel ou total.</li>
    <li>En cas de désaccord prolongé (7 jours max) → remboursement automatique total selon les règles de la plateforme.</li>
</ul>

<!-- 5. DECLARATION -->
<h2>5. Déclaration des parties</h2>
<p>Le bailleur et le locataire reconnaissent que :</p>
<ul>
    <li>La plateforme agit <strong>uniquement comme intermédiaire technique</strong>.</li>
    <li>Les fonds ne sont <strong>pas détenus par la plateforme</strong>.</li>
    <li>Le présent document constitue une <strong>preuve contractuelle</strong>.</li>
</ul>

<!-- 6. SIGNATURES -->
<h2>6. Signatures électroniques</h2>

<div class="sig-box">
    <h3>Signature du Bailleur</h3>
    <div class="row">Nom : {$landlordName}</div>
    <div class="row">Statut : {$landlordSigned}</div>
</div>
&nbsp;&nbsp;
<div class="sig-box">
    <h3>Signature du Locataire</h3>
    <div class="row">Nom : {$tenantName}</div>
    <div class="row">Statut : {$tenantSigned}</div>
</div>

<div class="legal">
    📌 Document généré automatiquement par la plateforme Plan B le {$generatedAt} — valable comme preuve contractuelle.<br>
    La plateforme agit uniquement comme intermédiaire technique. Les fonds sont détenus par un prestataire de paiement agréé.
</div>

</body>
</html>
HTML;
    }
}
