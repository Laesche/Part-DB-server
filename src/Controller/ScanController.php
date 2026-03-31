<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2022 Jan Böhmer (https://github.com/jbtronics)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

/**
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 * Copyright (C) 2019 - 2022 Jan Böhmer (https://github.com/jbtronics)
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace App\Controller;

use App\Entity\Parts\PartLot;
use App\Entity\Parts\Part;
use App\Entity\Parts\Category;
use App\Entity\Parts\StorageLocation;
use App\Exceptions\InfoProviderNotActiveException;
use App\Form\LabelSystem\ScanDialogType;
use App\Services\InfoProviderSystem\PartInfoRetriever;
use App\Services\LabelSystem\BarcodeScanner\BarcodeScanResultInterface;
use App\Services\LabelSystem\BarcodeScanner\BarcodeScanHelper;
use App\Services\LabelSystem\BarcodeScanner\BarcodeSourceType;
use App\Services\LabelSystem\BarcodeScanner\BarcodeScanResultHandler;
use App\Services\LabelSystem\BarcodeScanner\EIGP114BarcodeScanResult;
use App\Services\LabelSystem\BarcodeScanner\LocalBarcodeScanResult;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\UX\Turbo\TurboBundle;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @see \App\Tests\Controller\ScanControllerTest
 */
#[Route(path: '/scan')]
class ScanController extends AbstractController
{
    private const QUICK_ADD_SESSION_KEY = 'scan.quick_add.pending';

    public function __construct(
        protected BarcodeScanResultHandler $resultHandler,
        protected BarcodeScanHelper $barcodeNormalizer,
    ) {}

    #[Route(path: '', name: 'scan_dialog')]
    public function dialog(Request $request, #[MapQueryParameter] ?string $input = null): Response
    {
        $this->denyAccessUnlessGranted('@tools.label_scanner');

        $form = $this->createForm(ScanDialogType::class);
        $form->handleRequest($request);

        // If JS is working, scanning uses /scan/lookup and this action just renders the page.
        // This fallback only runs if user submits the form manually or uses ?input=...
        if ($input === null && $form->isSubmitted() && $form->isValid()) {
            $input = $form['input']->getData();
        }


        if ($input !== null && $input !== '') {
            $mode = $form->isSubmitted() ? $form['mode']->getData() : null;
            $infoMode = $form->isSubmitted() && $form['info_mode']->getData();

            try {
                $scan = $this->barcodeNormalizer->scanBarcodeContent($input, $mode ?? null);

                // If not in info mode, mimic “normal scan” behavior: redirect if possible.
                if (!$infoMode) {

                    // Try to get an Info URL if possible
                    $url = $this->resultHandler->getInfoURL($scan);
                    if ($url !== null) {
                        return $this->redirect($url);
                    }

                    //Try to get an creation URL if possible (only for vendor codes)
                    $createUrl = $this->buildCreateUrlForScanResult($scan);
                    if ($createUrl !== null) {
                        return $this->redirect($createUrl);
                    }

                    //// Otherwise: show “not found” (not “format unknown”)
                    $this->addFlash('warning', 'scan.qr_not_found');
                } else { // Info mode
                    // Info mode fallback: render page with prefilled result
                    $decoded = $scan->getDecodedForInfoMode();

                    //Try to resolve to an entity, to enhance info mode with entity-specific data
                    $dbEntity = $this->resultHandler->resolveEntity($scan);
                    $resolvedPart = $this->resultHandler->resolvePart($scan);
                    $openUrl = $this->resultHandler->getInfoURL($scan);

                    //If no entity is found, try to create an URL for creating a new part (only for vendor codes)
                    $createUrl = null;
                    if ($dbEntity === null) {
                        $createUrl = $this->buildCreateUrlForScanResult($scan);
                    }

                    if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
                        $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
                        return $this->renderBlock('label_system/scanner/scanner.html.twig', 'scan_results', [
                            'decoded' => $decoded,
                            'entity' => $dbEntity,
                            'part' => $resolvedPart,
                            'openUrl' => $openUrl,
                            'createUrl' => $createUrl,
                        ]);
                    }

                }
            } catch (\Throwable $e) {
                // Keep fallback user-friendly; avoid 500
                $this->addFlash('warning', 'scan.format_unknown');
            }
        }

        //When we reach here, only the flash messages are relevant, so if it's a Turbo request, only send the flash message fragment, so the client can show it without a full page reload
        if (TurboBundle::STREAM_FORMAT === $request->getPreferredFormat()) {
            $request->setRequestFormat(TurboBundle::STREAM_FORMAT);
            //Only send our flash message, so the client can show it without a full page reload
            return $this->renderBlock('_turbo_control.html.twig', 'flashes');
        }

        return $this->render('label_system/scanner/scanner.html.twig', [
            'form' => $form,

            //Info mode
            'decoded' => $decoded ?? null,
            'entity' => $dbEntity ?? null,
            'part' => $resolvedPart ?? null,
            'openUrl' => $openUrl ?? null,
            'createUrl' => $createUrl ?? null,
        ]);
    }

    /**
     * The route definition for this action is done in routes.yaml, as it does not use the _locale prefix as the other routes.
     */
    public function scanQRCode(string $type, int $id): Response
    {
        $type = strtolower($type);

        try {
            $this->addFlash('success', 'scan.qr_success');

            if (!isset(BarcodeScanHelper::QR_TYPE_MAP[$type])) {
                throw new InvalidArgumentException('Unknown type: '.$type);
            }
            //Construct the scan result manually, as we don't have a barcode here
            $scan_result = new LocalBarcodeScanResult(
                target_type: BarcodeScanHelper::QR_TYPE_MAP[$type],
                target_id: $id,
                //The routes are only used on the internal generated QR codes
                source_type: BarcodeSourceType::INTERNAL
            );

            return $this->redirect($this->resultHandler->getInfoURL($scan_result) ?? throw new EntityNotFoundException("Not found"));
        } catch (EntityNotFoundException) {
            $this->addFlash('success', 'scan.qr_not_found');

            return $this->redirectToRoute('homepage');
        }
    }

    #[Route(path: '/quick-add', name: 'scan_quick_add', methods: ['GET'])]
    public function quickAddPage(EntityManagerInterface $em, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $this->denyAccessUnlessGranted('@tools.label_scanner');
        $this->denyAccessUnlessGranted('@info_providers.create_parts');
        $this->denyAccessUnlessGranted('create', new Part());
        $this->denyAccessUnlessGranted('@storelocations.read');

        $storageLocations = $em->getRepository(StorageLocation::class)->findAll();
        usort(
            $storageLocations,
            static fn (StorageLocation $a, StorageLocation $b): int => strcmp($a->getFullPath(), $b->getFullPath())
        );
        $categories = $em->getRepository(Category::class)->findBy([], ['name' => 'ASC']);
        $categories = array_values(array_filter(
            $categories,
            static fn (mixed $category): bool => $category instanceof Category && !$category->isNotSelectable()
        ));

        return $this->render('label_system/scanner/quick_add.html.twig', [
            'storageLocations' => $storageLocations,
            'categories' => $categories,
            'csrfToken' => $csrfTokenManager->getToken('scan_quick_add_confirm')->getValue(),
        ]);
    }

    #[Route(path: '/quick-add/lookup', name: 'scan_quick_add_lookup', methods: ['POST'])]
    public function quickAddLookup(
        Request $request,
        PartInfoRetriever $infoRetriever,
        EntityManagerInterface $em
    ): JsonResponse
    {
        $this->denyAccessUnlessGranted('@tools.label_scanner');
        $this->denyAccessUnlessGranted('@info_providers.create_parts');
        $this->denyAccessUnlessGranted('create', new Part());
        $this->denyAccessUnlessGranted('@storelocations.read');

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $input = trim((string) ($payload['input'] ?? ''));

        if ($input === '') {
            return $this->json(['ok' => false, 'message' => 'No barcode input given.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $scan = $this->barcodeNormalizer->scanBarcodeContent($input);
            $infoUrl = $this->resultHandler->getInfoURL($scan);
            if (is_string($infoUrl) && $infoUrl !== '') {
                return $this->json([
                    'ok' => true,
                    'redirectUrl' => $infoUrl,
                ]);
            }
            $createInfos = $this->resultHandler->getCreateInfos($scan);
        } catch (\Throwable) {
            return $this->json(['ok' => false, 'message' => 'Unknown or unsupported barcode format.'], Response::HTTP_BAD_REQUEST);
        }

        $isEigp114 = $scan instanceof EIGP114BarcodeScanResult;

        if ($createInfos === null) {
            return $this->json(['ok' => false, 'message' => 'This barcode cannot be used to create a part.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $dto = null;

        $deadline = microtime(true) + ($isEigp114 ? 20.0 : 0.0);
        do {
            try {
                $dto = $infoRetriever->getDetails($createInfos['providerKey'], $createInfos['providerId']);
                break;
            } catch (\Throwable $e) {
                if (!$isEigp114 || microtime(true) >= $deadline) {
                    break;
                }
                usleep(1_000_000);
            }
        } while ($isEigp114);

        if ($dto === null) {
            return $this->json([
                'ok' => false,
                'isEigp114' => $isEigp114,
                'message' => $isEigp114
                    ? 'Provider information is not available yet after waiting 20 seconds. Please scan again.'
                    : 'Failed to load provider details for this barcode.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $sessionToken = bin2hex(random_bytes(16));
        $pending = $request->getSession()->get(self::QUICK_ADD_SESSION_KEY, []);
        $pending[$sessionToken] = [
            'providerKey' => $createInfos['providerKey'],
            'providerId' => $createInfos['providerId'],
            'lotAmount' => isset($createInfos['lotAmount']) ? (float) $createInfos['lotAmount'] : 1.0,
            'lotName' => (string) ($createInfos['lotName'] ?? ''),
            'lotUserBarcode' => (string) ($createInfos['lotUserBarcode'] ?? ''),
        ];

        if (count($pending) > 25) {
            $pending = array_slice($pending, -25, 25, true);
        }

        $request->getSession()->set(self::QUICK_ADD_SESSION_KEY, $pending);

        $imageUrl = $dto->preview_image_url;
        if ($imageUrl === null && is_array($dto->images) && isset($dto->images[0])) {
            $imageUrl = $dto->images[0]->url;
        }

        $previewPart = $infoRetriever->dtoToPart($dto);
        $autoCategory = $previewPart->getCategory();
        if (!$autoCategory instanceof Category || $autoCategory->isNotSelectable()) {
            $autoCategory = $this->findFirstSelectableCategory($em);
        }

        return $this->json([
            'ok' => true,
            'scanToken' => $sessionToken,
            'isEigp114' => $isEigp114,
            'name' => $dto->name,
            'imageUrl' => $imageUrl,
            'amount' => isset($createInfos['lotAmount']) ? (float) $createInfos['lotAmount'] : 1.0,
            'autoCategoryId' => $autoCategory?->getID(),
        ]);
    }

    #[Route(path: '/quick-add/storage-lookup', name: 'scan_quick_add_storage_lookup', methods: ['POST'])]
    public function quickAddStorageLookup(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('@tools.label_scanner');
        $this->denyAccessUnlessGranted('@storelocations.read');

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $input = trim((string) ($payload['input'] ?? ''));
        if ($input === '') {
            return $this->json(['ok' => false, 'message' => 'No storage barcode input given.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $scan = $this->barcodeNormalizer->scanBarcodeContent($input);
            $entity = $this->resultHandler->resolveEntity($scan);
        } catch (\Throwable) {
            return $this->json(['ok' => false, 'message' => 'Unknown barcode format.'], Response::HTTP_BAD_REQUEST);
        }

        if (!$entity instanceof StorageLocation) {
            return $this->json(['ok' => false, 'message' => 'Scanned code is not a storage location label.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'ok' => true,
            'storageLocationId' => $entity->getID(),
            'storageLocationName' => $entity->getFullPath(),
        ]);
    }

    #[Route(path: '/quick-add/storage-create', name: 'scan_quick_add_storage_create', methods: ['POST'])]
    public function quickAddStorageCreate(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted('@tools.label_scanner');
        $this->denyAccessUnlessGranted('@storelocations.create');
        $this->denyAccessUnlessGranted('create', new StorageLocation());

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $csrf = (string) ($payload['_csrf_token'] ?? '');
        if (!$this->isCsrfTokenValid('scan_quick_add_confirm', $csrf)) {
            return $this->json(['ok' => false, 'message' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->json(['ok' => false, 'message' => 'Storage location name is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $location = new StorageLocation();
        $location->setName($name);
        $em->persist($location);
        $em->flush();

        return $this->json([
            'ok' => true,
            'storageLocationId' => $location->getID(),
            'storageLocationName' => $location->getFullPath(),
            'printUrl' => $this->buildPhomymoStorageLocationPrintUrl($location),
        ]);
    }

    #[Route(path: '/quick-add/confirm', name: 'scan_quick_add_confirm', methods: ['POST'])]
    public function quickAddConfirm(
        Request $request,
        PartInfoRetriever $infoRetriever,
        ValidatorInterface $validator,
        EntityManagerInterface $em,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('@tools.label_scanner');
        $this->denyAccessUnlessGranted('@info_providers.create_parts');
        $this->denyAccessUnlessGranted('create', new Part());
        $this->denyAccessUnlessGranted('@storelocations.read');

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $csrf = (string) ($payload['_csrf_token'] ?? '');
        if (!$this->isCsrfTokenValid('scan_quick_add_confirm', $csrf)) {
            return $this->json(['ok' => false, 'message' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $scanToken = (string) ($payload['scanToken'] ?? '');
        $pending = $request->getSession()->get(self::QUICK_ADD_SESSION_KEY, []);
        $scanData = $pending[$scanToken] ?? null;
        if (!is_array($scanData)) {
            return $this->json(['ok' => false, 'message' => 'Scan session expired. Please scan again.'], Response::HTTP_BAD_REQUEST);
        }

        $amount = (float) ($payload['amount'] ?? 0);
        if ($amount <= 0) {
            return $this->json(['ok' => false, 'message' => 'Amount must be greater than 0.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $storageLocationId = (int) ($payload['storageLocationId'] ?? 0);
        $storageLocation = $storageLocationId > 0
            ? $em->getRepository(StorageLocation::class)->find($storageLocationId)
            : null;
        $categoryId = (int) ($payload['categoryId'] ?? 0);

        try {
            $dto = $infoRetriever->getDetails($scanData['providerKey'], $scanData['providerId']);
            $part = $infoRetriever->dtoToPart($dto);
        } catch (\Throwable) {
            return $this->json(['ok' => false, 'message' => 'Could not create part from provider data.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($categoryId > 0) {
            $selectedCategory = $em->getRepository(Category::class)->find($categoryId);
            if ($selectedCategory instanceof Category && !$selectedCategory->isNotSelectable()) {
                $part->setCategory($selectedCategory);
            }
        }

        if (!$part->getCategory() instanceof Category || $part->getCategory()?->isNotSelectable()) {
            $fallbackCategory = $this->findFirstSelectableCategory($em);
            if ($fallbackCategory instanceof Category) {
                $part->setCategory($fallbackCategory);
            }
        }

        $partLot = new PartLot();
        $partLot->setAmount($amount);

        $lotName = trim((string) ($scanData['lotName'] ?? ''));
        $lotUserBarcode = trim((string) ($scanData['lotUserBarcode'] ?? ''));

        $partLot->setDescription($lotName);
        $partLot->setUserBarcode($lotUserBarcode !== '' ? $lotUserBarcode : null);
        if ($storageLocation instanceof StorageLocation) {
            $partLot->setStorageLocation($storageLocation);
        }
        $part->addPartLot($partLot);

        $violations = $validator->validate($part);
        if (count($violations) > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = trim((string) $violation->getMessage());
            }
            return $this->json([
                'ok' => false,
                'message' => implode(' ', array_filter($messages)),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $em->persist($part);
            $em->flush();
        } catch (\Throwable) {
            return $this->json([
                'ok' => false,
                'message' => $lotUserBarcode !== ''
                    ? 'Could not save the part. The scanned lot barcode may already exist.'
                    : 'Could not save the part. Please try again or choose a different category/storage location.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        unset($pending[$scanToken]);
        $request->getSession()->set(self::QUICK_ADD_SESSION_KEY, $pending);

        $printUrl = null;
        try {
            $printUrl = $this->buildPhomymoPrintUrl($part, $partLot);
        } catch (\Throwable) {
            $printUrl = null;
        }

        return $this->json([
            'ok' => true,
            'message' => 'Part added successfully.',
            'partId' => $part->getID(),
            'partUrl' => $this->generateUrl('part_edit', ['id' => $part->getID()]),
            'printUrl' => $printUrl,
        ]);
    }

    /**
     * Builds a URL for creating a new part based on the barcode data, handles exceptions and shows user-friendly error messages if the provider is not active or if there is an error during URL generation.
     * @param BarcodeScanResultInterface $scanResult
     * @return string|null
     */
    private function buildCreateUrlForScanResult(BarcodeScanResultInterface $scanResult): ?string
    {
        try {
            return $this->resultHandler->getCreationURL($scanResult);
        } catch (InfoProviderNotActiveException $e) {
            $this->addFlash('error', $e->getMessage());
        } catch (\Throwable) {
            // Don’t break scanning UX if provider lookup fails
            $this->addFlash('error', 'An error occurred while looking up the provider for this barcode. Please try again later.');
        }

        return null;
    }

    private function buildPhomymoPrintUrl(Part $part, PartLot $partLot): string
    {
        $barcodeUrl = $this->generateUrl('scan_qr', [
            'type' => 'part',
            'id' => $part->getID(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $payload = [
            'layout' => 'part_qr_left',
            'name' => $part->getName(),
            'category' => $part->getCategory()?->getFullPath() ?? '',
            'barcode' => $barcodeUrl,
        ];

        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            $json = '{"name":"Part","category":"","storageLocation":"","barcode":""}';
        }
        $encodedBase64 = base64_encode($json);
        if (!is_string($encodedBase64)) {
            $encodedBase64 = '';
        }
        $encoded = rtrim(strtr($encodedBase64, '+/', '-_'), '=');
        $returnUrl = $this->generateUrl('scan_quick_add');

        return '/phomymo/index.html?autolabel=' . rawurlencode($encoded) . '&autoprint=1&return=' . rawurlencode($returnUrl);
    }

    private function buildPhomymoStorageLocationPrintUrl(StorageLocation $location): string
    {
        $partsFilteredUrl = $this->generateUrl('parts_show_all', [
            'part_filter' => [
                'storelocation' => [
                    'operator' => '=',
                    'value' => $location->getID(),
                ],
            ],
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $payload = [
            'name' => $location->getName(),
            'category' => 'Storage Location',
            'storageLocation' => $location->getFullPath(),
            'barcode' => $partsFilteredUrl,
        ];

        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            $json = '{"name":"Storage Location","category":"Storage Location","storageLocation":"","barcode":""}';
        }
        $encodedBase64 = base64_encode($json);
        if (!is_string($encodedBase64)) {
            $encodedBase64 = '';
        }
        $encoded = rtrim(strtr($encodedBase64, '+/', '-_'), '=');
        $returnUrl = $this->generateUrl('scan_quick_add');

        return '/phomymo/index.html?autolabel=' . rawurlencode($encoded) . '&autoprint=1&return=' . rawurlencode($returnUrl);
    }

    private function findFirstSelectableCategory(EntityManagerInterface $em): ?Category
    {
        $categories = $em->getRepository(Category::class)->findBy([], ['name' => 'ASC']);
        foreach ($categories as $category) {
            if ($category instanceof Category && !$category->isNotSelectable()) {
                return $category;
            }
        }

        return null;
    }
}
