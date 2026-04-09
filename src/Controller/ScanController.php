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

use App\Entity\Attachments\Attachment;
use App\Entity\Parts\PartLot;
use App\Entity\Parts\Part;
use App\Entity\Parts\Category;
use App\Entity\Parts\StorageLocation;
use App\Exceptions\InfoProviderNotActiveException;
use App\Services\Attachments\AttachmentURLGenerator;
use App\Services\Attachments\PartPreviewGenerator;
use App\Form\LabelSystem\ScanDialogType;
use App\Services\InfoProviderSystem\PartInfoRetriever;
use App\Services\LabelSystem\BarcodeScanner\BarcodeScanResultInterface;
use App\Services\LabelSystem\BarcodeScanner\BarcodeScanHelper;
use App\Services\LabelSystem\BarcodeScanner\BarcodeSourceType;
use App\Services\LabelSystem\BarcodeScanner\BarcodeScanResultHandler;
use App\Services\LabelSystem\BarcodeScanner\EIGP114BarcodeScanResult;
use App\Services\LabelSystem\BarcodeScanner\GTINBarcodeScanResult;
use App\Services\LabelSystem\BarcodeScanner\LocalBarcodeScanResult;
use App\Services\Parts\PartLotWithdrawAddHelper;
use App\Services\Parts\CategorySuggestionService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
    private const MANUAL_REVIEW_PROVIDER_KEYS = ['google_last_resort'];

    public function __construct(
        protected BarcodeScanResultHandler $resultHandler,
        protected BarcodeScanHelper $barcodeNormalizer,
        private readonly PartPreviewGenerator $partPreviewGenerator,
        private readonly AttachmentURLGenerator $attachmentURLGenerator,
        private readonly CategorySuggestionService $categorySuggestionService,
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

    #[Route(path: '/storage-location-label', name: 'scan_storage_location_label', methods: ['GET', 'POST'])]
    public function storageLocationLabelPage(
        Request $request,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrfTokenManager,
    ): Response {
        $this->denyAccessUnlessGranted('@tools.label_scanner');
        $this->denyAccessUnlessGranted('@storelocations.create');
        $this->denyAccessUnlessGranted('create', new StorageLocation());

        if ($request->isMethod('POST')) {
            $csrf = (string) $request->request->get('_csrf_token', '');
            if (!$this->isCsrfTokenValid('scan_storage_location_label_create', $csrf)) {
                $this->addFlash('error', 'Invalid CSRF token.');
                return $this->redirectToRoute('scan_storage_location_label');
            }

            $name = trim((string) $request->request->get('name', ''));
            if ($name === '') {
                $this->addFlash('error', 'Storage location name is required.');
                return $this->redirectToRoute('scan_storage_location_label');
            }

            $location = new StorageLocation();
            $location->setName($name);

            try {
                $em->persist($location);
                $em->flush();
            } catch (\Throwable) {
                $this->addFlash('error', 'Could not create storage location.');
                return $this->redirectToRoute('scan_storage_location_label');
            }

            $printUrl = $this->buildPhomymoStorageLocationPrintUrl($location);

            return new RedirectResponse($printUrl);
        }

        return $this->render('label_system/scanner/storage_location_label.html.twig', [
            'csrfToken' => $csrfTokenManager->getToken('scan_storage_location_label_create')->getValue(),
        ]);
    }

    #[Route(path: '/storage-location-allocator', name: 'scan_storage_location_allocator', methods: ['GET'])]
    public function storageLocationAllocatorPage(CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $this->denyAccessUnlessGranted('@tools.label_scanner');
        $this->denyAccessUnlessGranted('@storelocations.read');
        $this->denyAccessUnlessGranted('@info_providers.create_parts');
        $this->denyAccessUnlessGranted('create', new Part());

        return $this->render('label_system/scanner/storage_location_allocator.html.twig', [
            'csrfToken' => $csrfTokenManager->getToken('scan_quick_add_confirm')->getValue(),
        ]);
    }

    #[Route(path: '/stock-terminal', name: 'scan_stock_terminal', methods: ['GET'])]
    public function stockTerminalPage(CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        $this->denyAccessUnlessGranted('@tools.label_scanner');
        $this->denyAccessUnlessGranted('@parts.read');
        $this->denyAccessUnlessGranted('@storelocations.read');
        $this->denyAccessUnlessGranted('@info_providers.create_parts');
        $this->denyAccessUnlessGranted('create', new Part());

        return $this->render('label_system/scanner/stock_terminal.html.twig', [
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

        $directRedirectUrl = $this->normalizeDirectRedirectInput($request, $input);
        if ($directRedirectUrl !== null) {
            return $this->json([
                'ok' => true,
                'redirectUrl' => $directRedirectUrl,
            ]);
        }

        $existingLot = $em->getRepository(PartLot::class)->findOneBy(['user_barcode' => $input]);
        if ($existingLot instanceof PartLot) {
            return $this->json([
                'ok' => true,
                'redirectUrl' => $this->generateUrl('app_part_show', [
                    'id' => $existingLot->getPart()?->getID(),
                    'highlightLot' => $existingLot->getID(),
                ]),
            ]);
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

        if ($this->providerRequiresManualReview($createInfos)) {
            return $this->json([
                'ok' => true,
                'redirectUrl' => $this->buildInfoProviderCreateRedirectUrl($createInfos),
            ]);
        }

        $derivedLotBarcode = trim((string) ($createInfos['lotUserBarcode'] ?? ''));
        if ($derivedLotBarcode !== '') {
            $existingDerivedLot = $em->getRepository(PartLot::class)->findOneBy(['user_barcode' => $derivedLotBarcode]);
            if ($existingDerivedLot instanceof PartLot) {
                return $this->json([
                    'ok' => true,
                    'redirectUrl' => $this->generateUrl('app_part_show', [
                        'id' => $existingDerivedLot->getPart()?->getID(),
                        'highlightLot' => $existingDerivedLot->getID(),
                    ]),
                ]);
            }
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
        $autoCategoryPath = null;
        if (!$autoCategory instanceof Category || $autoCategory->isNotSelectable()) {
            $providerCategoryPath = $this->determineAutoCategoryPath($dto);
            if ($providerCategoryPath !== null) {
                $autoCategoryPath = $providerCategoryPath;
            } else {
                $autoCategory = $this->findFirstSelectableCategory($em);
            }
        }

        return $this->json([
            'ok' => true,
            'scanToken' => $sessionToken,
            'isEigp114' => $isEigp114,
            'name' => $dto->name,
            'imageUrl' => $imageUrl,
            'amount' => isset($createInfos['lotAmount']) ? (float) $createInfos['lotAmount'] : 1.0,
            'autoCategoryId' => $autoCategory?->getID(),
            'autoCategoryPath' => $autoCategoryPath,
        ]);
    }

    #[Route(path: '/quick-add/clear-pending', name: 'scan_quick_add_clear_pending', methods: ['POST'])]
    public function quickAddClearPending(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('@tools.label_scanner');
        $request->getSession()->remove(self::QUICK_ADD_SESSION_KEY);

        return $this->json([
            'ok' => true,
        ]);
    }

    #[Route(path: '/quick-add/storage-lookup', name: 'scan_quick_add_storage_lookup', methods: ['POST'])]
    public function quickAddStorageLookup(Request $request, EntityManagerInterface $em): JsonResponse
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

        $storageLocation = $this->resolveStorageLocationFromDirectUrl($input, $request, $em);
        if ($storageLocation instanceof StorageLocation) {
            return $this->json([
                'ok' => true,
                'storageLocationId' => $storageLocation->getID(),
                'storageLocationName' => $storageLocation->getFullPath(),
            ]);
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
        $categoryPath = $this->normalizeCategoryPath((string) ($payload['categoryPath'] ?? ''));
        $lotUserBarcode = trim((string) ($scanData['lotUserBarcode'] ?? ''));

        if ($lotUserBarcode !== '') {
            $existingLot = $em->getRepository(PartLot::class)->findOneBy(['user_barcode' => $lotUserBarcode]);
            if ($existingLot instanceof PartLot) {
                return $this->json([
                    'ok' => true,
                    'redirectUrl' => $this->generateUrl('app_part_show', [
                        'id' => $existingLot->getPart()?->getID(),
                        'highlightLot' => $existingLot->getID(),
                    ]),
                    'message' => 'Lot barcode already exists. Redirecting to the existing part.',
                ]);
            }
        }

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
        } elseif ($categoryPath !== null) {
            $part->setCategory($this->findOrCreateCategoryPath($em, $categoryPath));
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
        } catch (\Throwable $e) {
            if ($lotUserBarcode !== '') {
                $existingLot = $em->getRepository(PartLot::class)->findOneBy(['user_barcode' => $lotUserBarcode]);
                if ($existingLot instanceof PartLot) {
                    return $this->json([
                        'ok' => true,
                        'redirectUrl' => $this->generateUrl('app_part_show', [
                            'id' => $existingLot->getPart()?->getID(),
                            'highlightLot' => $existingLot->getID(),
                        ]),
                        'message' => 'Lot barcode already exists. Redirecting to the existing part.',
                    ]);
                }
            }

            $message = 'Could not save the part. Please review the selected category and storage location.';
            if ($lotUserBarcode !== '' && $this->containsUniqueConstraintViolation($e)) {
                $message = 'Could not save the part. The scanned lot barcode already exists.';
            } else {
                $rootMessage = $this->getRootExceptionMessage($e);
                if ($rootMessage !== null) {
                    $message = 'Could not save the part. ' . $rootMessage;
                }
            }

            return $this->json([
                'ok' => false,
                'message' => $message,
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

    #[Route(path: '/storage-location-allocator/scan', name: 'scan_storage_location_allocator_scan', methods: ['POST'])]
    public function storageLocationAllocatorScan(
        Request $request,
        PartInfoRetriever $infoRetriever,
        ValidatorInterface $validator,
        EntityManagerInterface $em,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('@tools.label_scanner');
        $this->denyAccessUnlessGranted('@storelocations.read');
        $this->denyAccessUnlessGranted('@info_providers.create_parts');
        $this->denyAccessUnlessGranted('create', new Part());

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $csrf = (string) ($payload['_csrf_token'] ?? '');
        if (!$this->isCsrfTokenValid('scan_quick_add_confirm', $csrf)) {
            return $this->json(['ok' => false, 'message' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $input = trim((string) ($payload['input'] ?? ''));
        if ($input === '') {
            return $this->json(['ok' => false, 'message' => 'No barcode input given.'], Response::HTTP_BAD_REQUEST);
        }

        $currentLocationId = (int) ($payload['storageLocationId'] ?? 0);
        $currentLocation = $currentLocationId > 0
            ? $em->getRepository(StorageLocation::class)->find($currentLocationId)
            : null;

        $existingLot = $em->getRepository(PartLot::class)->findOneBy(['user_barcode' => $input]);
        if ($existingLot instanceof PartLot) {
            if (!$currentLocation instanceof StorageLocation) {
                return $this->json(['ok' => false, 'message' => 'Scan a storage location label first.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->allocatePartLotToStorageLocation($existingLot, $currentLocation, $validator, $em);
        }

        $directStorageLocation = $this->resolveStorageLocationFromDirectUrl($input, $request, $em);
        if ($directStorageLocation instanceof StorageLocation) {
            return $this->json([
                'ok' => true,
                'mode' => 'storage',
                'storageLocationId' => $directStorageLocation->getID(),
                'storageLocationName' => $directStorageLocation->getFullPath(),
                'message' => sprintf('Current storage location set to %s.', $directStorageLocation->getFullPath()),
            ]);
        }

        try {
            $scan = $this->barcodeNormalizer->scanBarcodeContent($input);
            $entity = $this->resultHandler->resolveEntity($scan);
        } catch (\Throwable) {
            return $this->json(['ok' => false, 'message' => 'Unknown or unsupported barcode format.'], Response::HTTP_BAD_REQUEST);
        }

        if ($entity instanceof StorageLocation) {
            return $this->json([
                'ok' => true,
                'mode' => 'storage',
                'storageLocationId' => $entity->getID(),
                'storageLocationName' => $entity->getFullPath(),
                'message' => sprintf('Current storage location set to %s.', $entity->getFullPath()),
            ]);
        }

        if (!$currentLocation instanceof StorageLocation) {
            return $this->json(['ok' => false, 'message' => 'Scan a storage location label first.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($entity instanceof PartLot) {
            return $this->allocatePartLotToStorageLocation($entity, $currentLocation, $validator, $em);
        }

        if ($entity instanceof Part) {
            return $this->allocatePartToStorageLocation($entity, $currentLocation, $validator, $em);
        }

        $createInfos = null;
        try {
            $createInfos = $this->resultHandler->getCreateInfos($scan);
        } catch (\Throwable) {
            $createInfos = null;
        }

        $isEigp114 = $scan instanceof EIGP114BarcodeScanResult;
        if ($createInfos === null) {
            return $this->json(['ok' => false, 'message' => 'This barcode cannot be used to allocate a part.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->providerRequiresManualReview($createInfos)) {
            return $this->json([
                'ok' => true,
                'redirectUrl' => $this->buildInfoProviderCreateRedirectUrl($createInfos),
                'message' => 'Web lookup found a possible product. Review it on the add-part screen before saving.',
            ]);
        }

        $derivedLotBarcode = trim((string) ($createInfos['lotUserBarcode'] ?? ''));
        if ($derivedLotBarcode !== '') {
            $existingDerivedLot = $em->getRepository(PartLot::class)->findOneBy(['user_barcode' => $derivedLotBarcode]);
            if ($existingDerivedLot instanceof PartLot) {
                return $this->allocatePartLotToStorageLocation($existingDerivedLot, $currentLocation, $validator, $em);
            }
        }

        $dto = $this->fetchProviderDto($infoRetriever, $createInfos, $isEigp114);
        if ($dto === null) {
            return $this->json([
                'ok' => false,
                'message' => $isEigp114
                    ? 'Provider information is not available yet after waiting 20 seconds. Please scan again.'
                    : 'Failed to load provider details for this barcode.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $part = $infoRetriever->dtoToPart($dto);
        } catch (\Throwable) {
            return $this->json(['ok' => false, 'message' => 'Could not create part from provider data.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $autoCategory = $part->getCategory();
        if (!$autoCategory instanceof Category || $autoCategory->isNotSelectable()) {
            $providerCategoryPath = $this->determineAutoCategoryPath($dto);
            if ($providerCategoryPath !== null) {
                $part->setCategory($this->findOrCreateCategoryPath($em, $providerCategoryPath));
            } else {
                $fallbackCategory = $this->findFirstSelectableCategory($em);
                if ($fallbackCategory instanceof Category) {
                    $part->setCategory($fallbackCategory);
                }
            }
        }

        $partLot = new PartLot();
        $partLot->setAmount(isset($createInfos['lotAmount']) ? (float) $createInfos['lotAmount'] : 1.0);
        $partLot->setDescription(trim((string) ($createInfos['lotName'] ?? '')));
        $partLot->setUserBarcode($derivedLotBarcode !== '' ? $derivedLotBarcode : null);
        $partLot->setStorageLocation($currentLocation);
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
        } catch (\Throwable $e) {
            $message = 'Could not save the part. Please review the selected category and storage location.';
            if ($derivedLotBarcode !== '' && $this->containsUniqueConstraintViolation($e)) {
                $message = 'Could not save the part. The scanned lot barcode already exists.';
            } else {
                $rootMessage = $this->getRootExceptionMessage($e);
                if ($rootMessage !== null) {
                    $message = 'Could not save the part. ' . $rootMessage;
                }
            }

            return $this->json([
                'ok' => false,
                'message' => $message,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'ok' => true,
            'mode' => 'item',
            'message' => sprintf('Created %s and allocated it to %s.', $part->getName(), $currentLocation->getFullPath()),
            'storageLocationId' => $currentLocation->getID(),
            'storageLocationName' => $currentLocation->getFullPath(),
            'partId' => $part->getID(),
        ]);
    }

    #[Route(path: '/stock-terminal/scan', name: 'scan_stock_terminal_scan', methods: ['POST'])]
    public function stockTerminalScan(
        Request $request,
        PartInfoRetriever $infoRetriever,
        ValidatorInterface $validator,
        EntityManagerInterface $em,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('@tools.label_scanner');
        $this->denyAccessUnlessGranted('@parts.read');
        $this->denyAccessUnlessGranted('@storelocations.read');
        $this->denyAccessUnlessGranted('@info_providers.create_parts');
        $this->denyAccessUnlessGranted('create', new Part());

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $csrf = (string) ($payload['_csrf_token'] ?? '');
        if (!$this->isCsrfTokenValid('scan_quick_add_confirm', $csrf)) {
            return $this->json(['ok' => false, 'message' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $input = trim((string) ($payload['input'] ?? ''));
        if ($input === '') {
            return $this->json(['ok' => false, 'message' => 'No barcode input given.'], Response::HTTP_BAD_REQUEST);
        }

        $currentLocationId = (int) ($payload['storageLocationId'] ?? 0);
        $currentLocation = $currentLocationId > 0
            ? $em->getRepository(StorageLocation::class)->find($currentLocationId)
            : null;

        $directStorageLocation = $this->resolveStorageLocationFromDirectUrl($input, $request, $em);
        if ($directStorageLocation instanceof StorageLocation) {
            $this->denyAccessUnlessGranted('read', $directStorageLocation);

            return $this->json([
                'ok' => true,
                'mode' => 'storage',
                'message' => sprintf('Storage location %s scanned.', $directStorageLocation->getFullPath()),
                'storageLocation' => [
                    'id' => $directStorageLocation->getID(),
                    'name' => $directStorageLocation->getName(),
                    'fullPath' => $directStorageLocation->getFullPath(),
                    'partsListUrl' => $this->buildStorageLocationPartsListUrl($directStorageLocation),
                ],
            ]);
        }

        $existingLot = $em->getRepository(PartLot::class)->findOneBy(['user_barcode' => $input]);
        if ($existingLot instanceof PartLot) {
            $this->denyAccessUnlessGranted('read', $existingLot);

            if ($currentLocation instanceof StorageLocation) {
                $allocationError = $this->movePartLotToStorageLocation($existingLot, $currentLocation, $validator, $em);
                if ($allocationError !== null) {
                    return $this->json(['ok' => false, 'message' => $allocationError], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }

            return $this->json($this->buildStockTerminalPartResponse(
                $existingLot->getPart() ?? throw new \RuntimeException('Part lot without part encountered.'),
                $existingLot,
                $currentLocation instanceof StorageLocation ? $currentLocation : null,
                $currentLocation instanceof StorageLocation
                    ? sprintf('Allocated %s to %s.', $existingLot->getPart()?->getName() ?? ('Lot #' . $existingLot->getID()), $currentLocation->getFullPath())
                    : 'Part scanned.'
            ));
        }

        try {
            $scan = $this->barcodeNormalizer->scanBarcodeContent($input);
            $entity = $this->resultHandler->resolveEntity($scan);
        } catch (\Throwable) {
            return $this->json(['ok' => false, 'message' => 'Unknown or unsupported barcode format.'], Response::HTTP_BAD_REQUEST);
        }

        if ($entity instanceof StorageLocation) {
            $this->denyAccessUnlessGranted('read', $entity);

            return $this->json([
                'ok' => true,
                'mode' => 'storage',
                'message' => sprintf('Storage location %s scanned.', $entity->getFullPath()),
                'storageLocation' => [
                    'id' => $entity->getID(),
                    'name' => $entity->getName(),
                    'fullPath' => $entity->getFullPath(),
                    'partsListUrl' => $this->buildStorageLocationPartsListUrl($entity),
                ],
            ]);
        }

        if ($entity instanceof PartLot) {
            $this->denyAccessUnlessGranted('read', $entity);

            if ($currentLocation instanceof StorageLocation) {
                $allocationError = $this->movePartLotToStorageLocation($entity, $currentLocation, $validator, $em);
                if ($allocationError !== null) {
                    return $this->json(['ok' => false, 'message' => $allocationError], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }

            return $this->json($this->buildStockTerminalPartResponse(
                $entity->getPart() ?? throw new \RuntimeException('Part lot without part encountered.'),
                $entity,
                $currentLocation instanceof StorageLocation ? $currentLocation : null,
                $currentLocation instanceof StorageLocation
                    ? sprintf('Allocated %s to %s.', $entity->getPart()?->getName() ?? ('Lot #' . $entity->getID()), $currentLocation->getFullPath())
                    : 'Part scanned.'
            ));
        }

        if ($entity instanceof Part) {
            $this->denyAccessUnlessGranted('read', $entity);

            $preferredLot = null;
            if ($currentLocation instanceof StorageLocation) {
                $preferredLot = $this->findPreferredPartLot($entity, null, $currentLocation, true);
                if (!$preferredLot instanceof PartLot) {
                    return $this->json(['ok' => false, 'message' => sprintf('Part %s has multiple lots. Scan a specific lot label instead.', $entity->getName())], Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $allocationError = $this->movePartLotToStorageLocation($preferredLot, $currentLocation, $validator, $em);
                if ($allocationError !== null) {
                    return $this->json(['ok' => false, 'message' => $allocationError], Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }

            return $this->json($this->buildStockTerminalPartResponse(
                $entity,
                $preferredLot,
                $currentLocation instanceof StorageLocation ? $currentLocation : null,
                $currentLocation instanceof StorageLocation && $preferredLot instanceof PartLot
                    ? sprintf('Allocated %s to %s.', $entity->getName(), $currentLocation->getFullPath())
                    : 'Part scanned.'
            ));
        }

        $createInfos = null;
        try {
            $createInfos = $this->resultHandler->getCreateInfos($scan);
        } catch (\Throwable) {
            $createInfos = null;
        }

        $isEigp114 = $scan instanceof EIGP114BarcodeScanResult;
        if ($createInfos === null) {
            return $this->json(['ok' => false, 'message' => 'This barcode cannot be used here.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($this->providerRequiresManualReview($createInfos)) {
            return $this->json([
                'ok' => true,
                'redirectUrl' => $this->buildInfoProviderCreateRedirectUrl($createInfos),
                'message' => 'Web lookup found a possible product. Review it on the add-part screen before saving.',
            ]);
        }

        $derivedLotBarcode = trim((string) ($createInfos['lotUserBarcode'] ?? ''));
        if ($derivedLotBarcode !== '') {
            $existingDerivedLot = $em->getRepository(PartLot::class)->findOneBy(['user_barcode' => $derivedLotBarcode]);
            if ($existingDerivedLot instanceof PartLot) {
                $this->denyAccessUnlessGranted('read', $existingDerivedLot);

                if ($currentLocation instanceof StorageLocation) {
                    $allocationError = $this->movePartLotToStorageLocation($existingDerivedLot, $currentLocation, $validator, $em);
                    if ($allocationError !== null) {
                        return $this->json(['ok' => false, 'message' => $allocationError], Response::HTTP_UNPROCESSABLE_ENTITY);
                    }
                }

                return $this->json($this->buildStockTerminalPartResponse(
                    $existingDerivedLot->getPart() ?? throw new \RuntimeException('Part lot without part encountered.'),
                    $existingDerivedLot,
                    $currentLocation instanceof StorageLocation ? $currentLocation : null,
                    $currentLocation instanceof StorageLocation
                        ? sprintf('Allocated %s to %s.', $existingDerivedLot->getPart()?->getName() ?? ('Lot #' . $existingDerivedLot->getID()), $currentLocation->getFullPath())
                        : 'Part scanned.'
                ));
            }
        }

        $dto = $this->fetchProviderDto($infoRetriever, $createInfos, $isEigp114);
        if ($dto === null) {
            return $this->json([
                'ok' => true,
                'redirectUrl' => $this->buildManualPartCreateRedirectUrl(
                    $input,
                    $scan,
                    $currentLocation instanceof StorageLocation ? $currentLocation : null,
                    $createInfos
                ),
                'message' => $isEigp114
                    ? 'Provider information is not available yet after waiting 20 seconds. Opening the add-part form with the scanned barcode linked.'
                    : 'Provider lookup failed. Opening the add-part form with the scanned barcode linked.',
            ]);
        }

        try {
            $part = $infoRetriever->dtoToPart($dto);
        } catch (\Throwable) {
            return $this->json(['ok' => false, 'message' => 'Could not create part from provider data.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $autoCategory = $part->getCategory();
        if (!$autoCategory instanceof Category || $autoCategory->isNotSelectable()) {
            $providerCategoryPath = $this->determineAutoCategoryPath($dto);
            if ($providerCategoryPath !== null) {
                $part->setCategory($this->findOrCreateCategoryPath($em, $providerCategoryPath));
            } else {
                $fallbackCategory = $this->findFirstSelectableCategory($em);
                if ($fallbackCategory instanceof Category) {
                    $part->setCategory($fallbackCategory);
                }
            }
        }

        $partLot = new PartLot();
        $partLot->setAmount(isset($createInfos['lotAmount']) ? (float) $createInfos['lotAmount'] : 1.0);
        $partLot->setDescription(trim((string) ($createInfos['lotName'] ?? '')));
        $partLot->setUserBarcode($derivedLotBarcode !== '' ? $derivedLotBarcode : null);
        if ($currentLocation instanceof StorageLocation) {
            $partLot->setStorageLocation($currentLocation);
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
        } catch (\Throwable $e) {
            $message = 'Could not save the part. Please review the selected category and storage location.';
            if ($derivedLotBarcode !== '' && $this->containsUniqueConstraintViolation($e)) {
                $message = 'Could not save the part. The scanned lot barcode already exists.';
            } else {
                $rootMessage = $this->getRootExceptionMessage($e);
                if ($rootMessage !== null) {
                    $message = 'Could not save the part. ' . $rootMessage;
                }
            }

            return $this->json([
                'ok' => false,
                'message' => $message,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->buildStockTerminalPartResponse(
            $part,
            $partLot,
            $currentLocation instanceof StorageLocation ? $currentLocation : null,
            sprintf('Created %s%s.', $part->getName(), $currentLocation instanceof StorageLocation ? ' and assigned it to ' . $currentLocation->getFullPath() : ''),
            true
        ));
    }

    #[Route(path: '/stock-terminal/stock', name: 'scan_stock_terminal_stock', methods: ['POST'])]
    public function stockTerminalStockAdjust(
        Request $request,
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        PartLotWithdrawAddHelper $withdrawAddHelper,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('@tools.label_scanner');
        $this->denyAccessUnlessGranted('@parts.read');

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $csrf = (string) ($payload['_csrf_token'] ?? '');
        if (!$this->isCsrfTokenValid('scan_quick_add_confirm', $csrf)) {
            return $this->json(['ok' => false, 'message' => 'Invalid CSRF token.'], Response::HTTP_FORBIDDEN);
        }

        $partId = (int) ($payload['partId'] ?? 0);
        $part = $partId > 0 ? $em->getRepository(Part::class)->find($partId) : null;
        if (!$part instanceof Part) {
            return $this->json(['ok' => false, 'message' => 'Part not found.'], Response::HTTP_NOT_FOUND);
        }
        $this->denyAccessUnlessGranted('read', $part);

        $lotId = (int) ($payload['lotId'] ?? 0);
        $contextLocationId = (int) ($payload['storageLocationId'] ?? 0);
        $contextLocation = $contextLocationId > 0 ? $em->getRepository(StorageLocation::class)->find($contextLocationId) : null;
        $action = trim((string) ($payload['action'] ?? ''));
        $amount = (float) ($payload['amount'] ?? 0);

        if ($amount <= 0) {
            return $this->json(['ok' => false, 'message' => 'Amount must be greater than 0.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $lot = $lotId > 0 ? $em->getRepository(PartLot::class)->find($lotId) : null;
        if ($lot instanceof PartLot && $lot->getPart() !== $part) {
            return $this->json(['ok' => false, 'message' => 'Part lot does not belong to the part.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!$lot instanceof PartLot) {
            $lot = $this->findPreferredPartLot($part, null, $contextLocation instanceof StorageLocation ? $contextLocation : null, $action === 'add');
        }

        if (!$lot instanceof PartLot) {
            return $this->json(['ok' => false, 'message' => 'Could not determine which lot to change. Scan a specific lot label or enter add-to-location mode.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($lot->getPart() !== $part) {
            $part->addPartLot($lot);
            $em->persist($lot);
        }

        if ($contextLocation instanceof StorageLocation && !$lot->getStorageLocation() instanceof StorageLocation) {
            $lot->setStorageLocation($contextLocation);
        }

        $violations = $validator->validate($lot);
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
            if ($action === 'add') {
                $this->denyAccessUnlessGranted('add', $lot);
                $withdrawAddHelper->add($lot, $amount);
            } elseif ($action === 'reduce') {
                $this->denyAccessUnlessGranted('withdraw', $lot);
                $withdrawAddHelper->withdraw($lot, $amount);
            } else {
                return $this->json(['ok' => false, 'message' => 'Unknown stock action.'], Response::HTTP_BAD_REQUEST);
            }

            $em->flush();
        } catch (\Throwable $e) {
            $rootMessage = $this->getRootExceptionMessage($e);
            return $this->json([
                'ok' => false,
                'message' => $rootMessage !== null ? 'Could not change stock. ' . $rootMessage : 'Could not change stock.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json($this->buildStockTerminalPartResponse(
            $part,
            $lot,
            $contextLocation instanceof StorageLocation ? $contextLocation : null,
            $action === 'add' ? 'Stock added successfully.' : 'Stock reduced successfully.'
        ));
    }

    #[Route(path: '/stock-terminal/part/{id}', name: 'scan_stock_terminal_part', methods: ['GET'])]
    public function stockTerminalPartDetails(Part $part, Request $request, EntityManagerInterface $em): JsonResponse
    {
        $this->denyAccessUnlessGranted('@tools.label_scanner');
        $this->denyAccessUnlessGranted('read', $part);

        $contextLocationId = $request->query->getInt('storageLocationId', 0);
        $contextLocation = $contextLocationId > 0 ? $em->getRepository(StorageLocation::class)->find($contextLocationId) : null;

        return $this->json($this->buildStockTerminalPartResponse(
            $part,
            null,
            $contextLocation instanceof StorageLocation ? $contextLocation : null,
            'Part opened from search.'
        ));
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

        return '/phomymo/index.html?autolabel=' . rawurlencode($encoded) . '&autoprint=1';
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

        return '/phomymo/index.html?autolabel=' . rawurlencode($encoded) . '&autoprint=1';
    }

    private function fetchProviderDto(PartInfoRetriever $infoRetriever, array $createInfos, bool $isEigp114): mixed
    {
        $dto = null;
        $deadline = microtime(true) + ($isEigp114 ? 20.0 : 0.0);

        do {
            try {
                $dto = $infoRetriever->getDetails($createInfos['providerKey'], $createInfos['providerId']);
                break;
            } catch (\Throwable) {
                if (!$isEigp114 || microtime(true) >= $deadline) {
                    break;
                }
                usleep(1_000_000);
            }
        } while ($isEigp114);

        return $dto;
    }

    private function providerRequiresManualReview(array $createInfos): bool
    {
        $providerKey = (string) ($createInfos['providerKey'] ?? '');
        return in_array($providerKey, self::MANUAL_REVIEW_PROVIDER_KEYS, true);
    }

    private function buildInfoProviderCreateRedirectUrl(array $createInfos): string
    {
        $params = [
            'providerKey' => (string) $createInfos['providerKey'],
            'providerId' => (string) $createInfos['providerId'],
        ];

        if (isset($createInfos['lotAmount'])) {
            $params['lotAmount'] = (string) $createInfos['lotAmount'];
        }
        if (isset($createInfos['lotName'])) {
            $params['lotName'] = (string) $createInfos['lotName'];
        }
        if (isset($createInfos['lotUserBarcode'])) {
            $params['lotUserBarcode'] = (string) $createInfos['lotUserBarcode'];
        }

        return $this->generateUrl('info_providers_create_part', $params);
    }

    private function buildManualPartCreateRedirectUrl(
        string $input,
        ?BarcodeScanResultInterface $scanResult = null,
        ?StorageLocation $storageLocation = null,
        ?array $createInfos = null,
    ): string {
        $params = [];

        $linkedBarcode = trim((string) ($createInfos['lotUserBarcode'] ?? $input));
        if ($linkedBarcode !== '') {
            $params['lotUserBarcode'] = $linkedBarcode;
        }

        if (isset($createInfos['lotAmount'])) {
            $params['lotAmount'] = (string) $createInfos['lotAmount'];
        }
        if (isset($createInfos['lotName'])) {
            $params['lotName'] = (string) $createInfos['lotName'];
        }
        if ($scanResult instanceof GTINBarcodeScanResult) {
            $params['gtin'] = $scanResult->gtin;
        }
        if ($storageLocation instanceof StorageLocation) {
            $params['storelocation'] = $storageLocation->getID();
        }

        return $this->generateUrl('part_new', $params);
    }

    private function determineAutoCategoryPath(mixed $dto): ?string
    {
        $providerCategoryPath = $this->normalizeCategoryPath((string) ($dto->category ?? ''));
        if ($providerCategoryPath !== null) {
            return $providerCategoryPath;
        }

        return $this->categorySuggestionService->guessCategoryPathFromTexts(
            is_string($dto->name ?? null) ? $dto->name : null,
            is_string($dto->description ?? null) ? $dto->description : null,
            is_string($dto->notes ?? null) ? $dto->notes : null,
        );
    }

    private function buildStockTerminalPartResponse(
        Part $part,
        ?PartLot $preferredLot = null,
        ?StorageLocation $contextLocation = null,
        string $message = 'Part scanned.',
        bool $newlyCreated = false,
    ): array {
        $this->denyAccessUnlessGranted('read', $part);

        $lot = $this->findPreferredPartLot($part, $preferredLot, $contextLocation, false);

        $locations = [];
        foreach ($part->getPartLots() as $partLot) {
            if (!$partLot instanceof PartLot) {
                continue;
            }

            $location = $partLot->getStorageLocation();
            if ($location instanceof StorageLocation) {
                $locations[$location->getID()] = $location->getFullPath();
            }
        }
        $locationNames = array_values($locations);

        return [
            'ok' => true,
            'mode' => 'part',
            'message' => $message,
            'part' => [
                'id' => $part->getID(),
                'name' => $part->getName(),
                'category' => $part->getCategory()?->getFullPath() ?? '',
                'image' => $this->getPartPreviewImageUrl($part),
                'overallStock' => $part->getAmountSum(),
                'stockUnknown' => $part->isAmountUnknown(),
                'storageLocation' => $lot?->getStorageLocation()?->getFullPath(),
                'storageLocations' => $locationNames,
                'storageLocationsLabel' => count($locationNames) > 0 ? implode(' | ', $locationNames) : 'No storage location assigned',
                'lotId' => $lot?->getID(),
                'lotAmount' => $lot?->isInstockUnknown() ? null : $lot?->getAmount(),
                'lotUnknown' => $lot?->isInstockUnknown() ?? true,
                'openUrl' => $this->generateUrl('part_info', ['id' => $part->getID()]),
                'newlyCreated' => $newlyCreated,
            ],
        ];
    }

    private function getPartPreviewImageUrl(Part $part): ?string
    {
        $previewAttachment = $this->partPreviewGenerator->getTablePreviewAttachment($part);
        if (!$previewAttachment instanceof Attachment) {
            return null;
        }

        return $this->attachmentURLGenerator->getThumbnailURL($previewAttachment, 'thumbnail_sm');
    }

    private function buildStorageLocationPartsListUrl(StorageLocation $location): string
    {
        return $this->generateUrl('parts_show_all', [
            'part_filter' => [
                'storelocation' => [
                    'operator' => '=',
                    'value' => $location->getID(),
                ],
            ],
        ]);
    }

    private function findPreferredPartLot(
        Part $part,
        ?PartLot $preferredLot = null,
        ?StorageLocation $contextLocation = null,
        bool $createIfMissing = false,
    ): ?PartLot {
        if ($preferredLot instanceof PartLot && $preferredLot->getPart() === $part) {
            return $preferredLot;
        }

        if ($contextLocation instanceof StorageLocation) {
            $matchingLots = [];
            foreach ($part->getPartLots() as $partLot) {
                if ($partLot instanceof PartLot && $partLot->getStorageLocation()?->getID() === $contextLocation->getID()) {
                    $matchingLots[] = $partLot;
                }
            }

            if (count($matchingLots) === 1) {
                return $matchingLots[0];
            }

            if (count($matchingLots) > 1) {
                return null;
            }

            if ($createIfMissing) {
                $lot = new PartLot();
                $lot->setInstockUnknown(false);
                $lot->setAmount(0.0);
                $lot->setStorageLocation($contextLocation);
                $part->addPartLot($lot);
                return $lot;
            }
        }

        if ($part->getPartLots()->count() === 1) {
            $singleLot = $part->getPartLots()->first();
            return $singleLot instanceof PartLot ? $singleLot : null;
        }

        if ($part->getPartLots()->count() === 0 && $createIfMissing) {
            $lot = new PartLot();
            $lot->setInstockUnknown(false);
            $lot->setAmount(0.0);
            if ($contextLocation instanceof StorageLocation) {
                $lot->setStorageLocation($contextLocation);
            }
            $part->addPartLot($lot);
            return $lot;
        }

        return null;
    }

    private function movePartLotToStorageLocation(
        PartLot $partLot,
        StorageLocation $storageLocation,
        ValidatorInterface $validator,
        EntityManagerInterface $em,
    ): ?string {
        $this->denyAccessUnlessGranted('edit', $partLot);

        $partLot->setStorageLocation($storageLocation);

        $violations = $validator->validate($partLot);
        if (count($violations) > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = trim((string) $violation->getMessage());
            }

            return implode(' ', array_filter($messages));
        }

        try {
            $em->flush();
        } catch (\Throwable $e) {
            $rootMessage = $this->getRootExceptionMessage($e);
            return $rootMessage !== null ? 'Could not update storage location. ' . $rootMessage : 'Could not update storage location.';
        }

        return null;
    }

    private function allocatePartToStorageLocation(
        Part $part,
        StorageLocation $storageLocation,
        ValidatorInterface $validator,
        EntityManagerInterface $em,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('edit', $part);

        $partLots = $part->getPartLots();
        if ($partLots->count() > 1) {
            return $this->json([
                'ok' => false,
                'message' => sprintf('Part %s has multiple lots. Scan a specific lot label instead.', $part->getName()),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($partLots->count() === 0) {
            $partLot = new PartLot();
            $partLot->setInstockUnknown(true);
            $partLot->setStorageLocation($storageLocation);
            $part->addPartLot($partLot);
        } else {
            $partLot = $partLots->first();
            if (!$partLot instanceof PartLot) {
                return $this->json(['ok' => false, 'message' => 'Could not determine the part lot to allocate.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $partLot->setStorageLocation($storageLocation);
        }

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
            $em->flush();
        } catch (\Throwable $e) {
            $rootMessage = $this->getRootExceptionMessage($e);
            return $this->json([
                'ok' => false,
                'message' => $rootMessage !== null ? 'Could not update storage location. ' . $rootMessage : 'Could not update storage location.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'ok' => true,
            'mode' => 'item',
            'message' => sprintf('Allocated %s to %s.', $part->getName(), $storageLocation->getFullPath()),
            'storageLocationId' => $storageLocation->getID(),
            'storageLocationName' => $storageLocation->getFullPath(),
            'partId' => $part->getID(),
        ]);
    }

    private function allocatePartLotToStorageLocation(
        PartLot $partLot,
        StorageLocation $storageLocation,
        ValidatorInterface $validator,
        EntityManagerInterface $em,
    ): JsonResponse {
        $this->denyAccessUnlessGranted('edit', $partLot);

        $partLot->setStorageLocation($storageLocation);

        $violations = $validator->validate($partLot);
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
            $em->flush();
        } catch (\Throwable $e) {
            $rootMessage = $this->getRootExceptionMessage($e);
            return $this->json([
                'ok' => false,
                'message' => $rootMessage !== null ? 'Could not update storage location. ' . $rootMessage : 'Could not update storage location.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'ok' => true,
            'mode' => 'item',
            'message' => sprintf(
                'Allocated %s to %s.',
                $partLot->getPart()?->getName() ?? ('Lot #' . $partLot->getID()),
                $storageLocation->getFullPath()
            ),
            'storageLocationId' => $storageLocation->getID(),
            'storageLocationName' => $storageLocation->getFullPath(),
            'partId' => $partLot->getPart()?->getID(),
            'partLotId' => $partLot->getID(),
        ]);
    }

    private function normalizeDirectRedirectInput(Request $request, string $input): ?string
    {
        if ($input === '') {
            return null;
        }

        if (str_starts_with($input, '/')) {
            return $input;
        }

        $parts = parse_url($input);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $path === '') {
            return null;
        }

        if ($host !== strtolower($request->getHost())) {
            return null;
        }

        $result = $path;
        if (isset($parts['query']) && $parts['query'] !== '') {
            $result .= '?' . $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $result .= '#' . $parts['fragment'];
        }

        return $result;
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

    private function resolveStorageLocationFromDirectUrl(
        string $input,
        Request $request,
        EntityManagerInterface $em,
    ): ?StorageLocation {
        $candidate = trim($input);
        if ($candidate === '') {
            return null;
        }

        if (str_starts_with($candidate, '/')) {
            $candidate = $request->getSchemeAndHttpHost() . $candidate;
        }

        $parts = parse_url($candidate);
        if (!is_array($parts)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        if ($path === '') {
            return null;
        }

        $normalizedPath = rtrim($path, '/');
        $validPaths = [
            rtrim($this->generateUrl('parts_show_all'), '/'),
        ];

        if (!in_array($normalizedPath, $validPaths, true)) {
            return null;
        }

        $query = (string) ($parts['query'] ?? '');
        if ($query === '') {
            return null;
        }

        parse_str($query, $queryParams);
        $locationValue = $queryParams['part_filter']['storelocation']['value'] ?? null;
        $operator = $queryParams['part_filter']['storelocation']['operator'] ?? null;

        if ((string) $operator !== '=' || !is_scalar($locationValue)) {
            return null;
        }

        $locationId = (int) $locationValue;
        if ($locationId <= 0) {
            return null;
        }

        $location = $em->getRepository(StorageLocation::class)->find($locationId);
        return $location instanceof StorageLocation ? $location : null;
    }

    private function normalizeCategoryPath(string $path): ?string
    {
        $path = str_replace(['—', '–', ' > ', ' / '], ['-', '-', ' -> ', ' -> '], $path);
        if (!str_contains($path, '->') && preg_match('/\s-\s/u', $path) === 1) {
            $path = preg_replace('/\s-\s/u', ' -> ', $path) ?? $path;
        }

        $segments = preg_split('/\s*->\s*/', trim($path)) ?: [];
        $segments = array_values(array_filter(array_map(
            static fn (string $segment): string => trim($segment),
            $segments
        ), static fn (string $segment): bool => $segment !== ''));

        if ($segments === []) {
            return null;
        }

        return implode(' -> ', $segments);
    }

    private function findOrCreateCategoryPath(EntityManagerInterface $em, string $path): Category
    {
        $segments = explode(' -> ', $path);
        $parent = null;
        $current = null;

        foreach ($segments as $segment) {
            $found = $em->getRepository(Category::class)->findOneBy([
                'name' => $segment,
                'parent' => $parent,
            ]);

            if ($found instanceof Category) {
                $current = $found;
                $parent = $found;
                continue;
            }

            $current = new Category();
            $current->setName($segment);
            $current->setAlternativeNames($segment);
            $current->setParent($parent);
            $em->persist($current);
            $parent = $current;
        }

        if (!$current instanceof Category) {
            throw new \RuntimeException('Could not create category path.');
        }

        return $current;
    }

    private function containsUniqueConstraintViolation(\Throwable $exception): bool
    {
        $current = $exception;
        while ($current !== null) {
            if ($current instanceof UniqueConstraintViolationException) {
                return true;
            }
            $current = $current->getPrevious();
        }

        return false;
    }

    private function getRootExceptionMessage(\Throwable $exception): ?string
    {
        $current = $exception;
        while ($current->getPrevious() instanceof \Throwable) {
            $current = $current->getPrevious();
        }

        $message = trim($current->getMessage());
        if ($message === '') {
            return null;
        }

        $message = preg_replace('/\s+/', ' ', $message);
        if (!is_string($message) || $message === '') {
            return null;
        }

        return $message;
    }
}
