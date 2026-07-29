<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Container;
use App\Core\Request;

final class ExternalAuditController
{
    public function index(Request $request): void
    {
        authorize_access('audit.external.view');
        $restaurantId = current_restaurant_id();
        $date = (string) ($request->query['date'] ?? today_for_restaurant());
        $service = Container::getInstance()->get('externalAudit');
        $dashboard = $service->dashboard(
            $restaurantId,
            $date,
            can_access('audit.external.manage') ? null : (int) current_user()['id']
        );

        view('external-audit/index', [
            'title' => 'Audit externe',
            'date' => $date,
            'dashboard' => $dashboard,
            'categories' => $service->categories($restaurantId),
            'products' => $service->products($restaurantId),
            'users' => $service->activeUsers($restaurantId),
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);
    }

    public function createCategory(Request $request): void
    {
        authorize_access('audit.external.manage');
        Container::getInstance()->get('externalAudit')->createCategory(
            current_restaurant_id(),
            (string) $request->input('name'),
            (string) $request->input('audit_mode', 'stock'),
            current_user()
        );
        flash('success', 'Categorie Audit externe enregistree.');
        redirect('/audit-externe');
    }

    public function createProduct(Request $request): void
    {
        authorize_access('audit.external.manage');
        Container::getInstance()->get('externalAudit')->createProduct(current_restaurant_id(), $request->request, current_user());
        flash('success', 'Produit Audit externe enregistre avec sa categorie explicite.');
        redirect('/audit-externe');
    }

    public function saveDraft(Request $request): void
    {
        authorize_access('audit.external.view');
        $reportId = Container::getInstance()->get('externalAudit')->saveDraft(
            current_restaurant_id(),
            $request->request,
            current_user()
        );
        flash('success', 'Brouillon enregistre.');
        redirect('/audit-externe/rapports/' . $reportId);
    }

    public function show(Request $request): void
    {
        authorize_access('audit.external.view');
        $restaurantId = current_restaurant_id();
        $reportId = (int) $request->route('id');
        $service = Container::getInstance()->get('externalAudit');
        $report = $service->findReport($restaurantId, $reportId);
        if (!can_access('audit.external.manage') && (int) $report['operational_author_id'] !== (int) current_user()['id']) {
            http_response_code(403);
            echo 'Rapport Audit externe non autorise.';
            return;
        }
        view('external-audit/report', [
            'title' => 'Rapport Audit externe',
            'report' => $report,
            'items' => $service->items($restaurantId, $reportId),
            'result' => $service->result($restaurantId, $reportId),
            'revisions' => $service->revisions($restaurantId, $reportId),
            'correction_requests' => $service->correctionRequests($restaurantId, $reportId),
            'losses' => can_access('audit.external.manage') ? $service->lossAnalysis($restaurantId, $report['activity_date'], $report['activity_date'])['rows'] : [],
            'products' => $service->productsForReport($restaurantId, $report['activity_date']),
            'flash_success' => flash('success'),
            'flash_error' => flash('error'),
        ]);
    }

    public function submit(Request $request): void
    {
        authorize_access('audit.external.view');
        $reportId = (int) $request->route('id');
        Container::getInstance()->get('externalAudit')->submit(
            current_restaurant_id(),
            $reportId,
            (string) $request->input('idempotency_key'),
            current_user()
        );
        flash('success', 'Rapport soumis et verrouille pour son auteur. Les prix et calculs sont figes.');
        redirect('/audit-externe/rapports/' . $reportId);
    }

    public function reset(Request $request): void
    {
        authorize_access('audit.reset_report');
        $reportId = (int) $request->route('id');
        Container::getInstance()->get('externalAudit')->reset(
            current_restaurant_id(),
            $reportId,
            (string) $request->input('reason'),
            current_user()
        );
        flash('success', 'Version archivee. Un nouveau brouillon vide est ouvert.');
        redirect('/audit-externe/rapports/' . $reportId);
    }

    public function period(Request $request): void
    {
        authorize_access('audit.external.manage');
        [$from, $to] = $this->periodBounds($request);
        $restaurantId = current_restaurant_id();
        view('external-audit/period', [
            'title' => 'Rapport Audit externe',
            'period' => Container::getInstance()->get('externalAudit')->periodData($restaurantId, $from, $to),
            'restaurant' => Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId),
        ]);
    }

    public function excel(Request $request): void
    {
        authorize_access('audit.external.manage');
        [$from, $to] = $this->periodBounds($request);
        $restaurantId = current_restaurant_id();
        $data = Container::getInstance()->get('externalAudit')->periodData($restaurantId, $from, $to);
        $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        $content = Container::getInstance()->get('externalAuditExport')->excel($data, $restaurant);
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="audit-externe-' . $from . '-' . $to . '.xls"');
        header('X-Content-Type-Options: nosniff');
        echo $content;
    }

    public function pdf(Request $request): void
    {
        authorize_access('audit.external.manage');
        [$from, $to] = $this->periodBounds($request);
        $restaurantId = current_restaurant_id();
        $data = Container::getInstance()->get('externalAudit')->periodData($restaurantId, $from, $to);
        $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        $content = Container::getInstance()->get('externalAuditExport')->pdf($data, $restaurant);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="audit-externe-' . $from . '-' . $to . '.pdf"');
        header('Content-Length: ' . strlen($content));
        echo $content;
    }

    public function requestCorrection(Request $request): void
    {
        authorize_access('audit.external.view');
        $reportId = (int) $request->route('id');
        Container::getInstance()->get('externalAudit')->requestCorrection(current_restaurant_id(), $reportId, (string) $request->input('reason'), current_user());
        flash('success', 'Demande de correction envoyee.');
        redirect('/audit-externe/rapports/' . $reportId);
    }

    public function decideCorrection(Request $request): void
    {
        authorize_access('audit.external.manage');
        $reportId = (int) $request->input('report_id');
        Container::getInstance()->get('externalAudit')->decideCorrection(
            current_restaurant_id(),
            (int) $request->route('id'),
            (string) $request->input('decision') === 'approve',
            (string) $request->input('note'),
            current_user()
        );
        flash('success', 'Decision de correction enregistree.');
        redirect('/audit-externe/rapports/' . $reportId);
    }

    public function lock(Request $request): void
    {
        authorize_access('audit.external.manage');
        $reportId = (int) $request->route('id');
        Container::getInstance()->get('externalAudit')->lock(current_restaurant_id(), $reportId, current_user());
        flash('success', 'Rapport verrouille.');
        redirect('/audit-externe/rapports/' . $reportId);
    }

    public function cancel(Request $request): void
    {
        authorize_access('audit.external.manage');
        $reportId = (int) $request->route('id');
        Container::getInstance()->get('externalAudit')->cancel(current_restaurant_id(), $reportId, (string) $request->input('reason'), current_user());
        flash('success', 'Rapport annule avec trace.');
        redirect('/audit-externe/rapports/' . $reportId);
    }

    public function createLoss(Request $request): void
    {
        authorize_access('audit.external.manage');
        $reportId = (int) $request->route('id');
        $payload = $request->request;
        $payload['report_id'] = $reportId;
        if (isset($_FILES['evidence'])) {
            $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant(current_restaurant_id());
            $path = Container::getInstance()->get('uploadService')->storeRestaurantImage(
                $_FILES['evidence'],
                (string) ($restaurant['restaurant_code'] ?? 'audit'),
                'audit-loss'
            );
            if ($path !== null) {
                $payload['evidence_path'] = $path;
            }
        }
        Container::getInstance()->get('externalAudit')->createLoss(current_restaurant_id(), $payload, current_user());
        flash('success', 'Dossier de perte enregistre sans qualification automatique.');
        redirect('/audit-externe/rapports/' . $reportId);
    }

    public function deleteTest(Request $request): void
    {
        authorize_access('audit.delete_test');
        if ((string) $request->input('confirmation') !== 'SUPPRIMER TEST' || (string) $request->input('confirmation_2') !== 'CONFIRMER') {
            throw new \RuntimeException('Double confirmation sandbox invalide.');
        }
        $restaurantId = current_restaurant_id();
        $reportId = (int) $request->route('id');
        $restaurant = Container::getInstance()->get('restaurantAdmin')->findRestaurant($restaurantId);
        Container::getInstance()->get('externalAudit')->deleteTestReport($restaurantId, $reportId, (string) $request->input('reason'), current_user(), $restaurant);
        flash('success', 'Rapport test supprime; journal de suppression conserve.');
        redirect('/audit-externe');
    }

    public function restoreRevision(Request $request): void
    {
        authorize_access('audit.external.manage');
        $reportId = (int) $request->input('report_id');
        Container::getInstance()->get('externalAudit')->restoreRevision(
            current_restaurant_id(),
            $reportId,
            (int) $request->route('id'),
            (string) $request->input('reason'),
            current_user()
        );
        flash('success', 'Version restauree dans un nouveau brouillon; etat courant archive.');
        redirect('/audit-externe/rapports/' . $reportId);
    }

    private function periodBounds(Request $request): array
    {
        $from = (string) ($request->query['from'] ?? $request->input('from', today_for_restaurant()));
        $to = (string) ($request->query['to'] ?? $request->input('to', $from));
        return [$from, $to];
    }
}
