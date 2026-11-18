<?php

declare(strict_types=1);

namespace App\Formation\Module14MultiSite\Provisioning;

interface SiteProvisionerInterface
{
    /**
     * Crée ou met à jour le channel décrit et le rattache à ses methods. N'écrit rien en base :
     * les entités sont persistées, le flush et la transaction relèvent de l'appelant.
     *
     * @throws SiteProvisioningException si un prérequis manque ou si le channel est invalide
     */
    public function provision(SiteDefinition $definition): ProvisioningReport;
}
