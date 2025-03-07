<?php
declare(strict_types = 1);

namespace Nordkirche\NkGoogleMap\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\TypoScript\TemplateService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Object\ObjectManagerInterface;
use TYPO3\CMS\Extbase\Utility\DebuggerUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;


class MapMarkerMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {

        $normalizedParams = $request->getAttribute('normalizedParams');
        $uri = $normalizedParams->getRequestUri();

        if (strpos($uri, '/marker') === 0) {

            $this->initTSFE($request);

            $data = [];
            $supportedObjects = [];

            /** @var Site $site */
            $site = $request->getAttribute('site');
            /** @var RootlineUtility $rootlineUtility */
            $rootlineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $site->getRootPageId());
            $rootline = $rootlineUtility->get();
            /** @var TemplateService $templateService */
            $templateService = GeneralUtility::makeInstance(TemplateService::class);
            $templateService->tt_track = 0;
            $templateService->runThroughTemplates($rootline);
            $templateService->generateConfig();

            $typoScriptService = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\TypoScript\TypoScriptService::class);
            $tsConfig = $typoScriptService->convertTypoScriptArrayToPlainArray($templateService->setup);

            $items = GeneralUtility::trimExplode(',',  (string)$request->getQueryParams()['items']);

            $config = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['nk_google_map'];
            foreach(GeneralUtility::trimExplode(',',  (string)$config['config_mapping']) as $mapping) {
                list($objectType, $className) = GeneralUtility::trimExplode(':', $mapping);
                $supportedObjects[$objectType] = $className;
            }

            foreach($items as $item) {
                list($object, $id) = GeneralUtility::trimExplode(':', $item);
                if ($object && $id) {
                    if (isset($supportedObjects[$object])) {
                        $data[$object][] = $id;
                    }
                }
            }

            $markerResult = '';
            // Retrieve objects
            foreach($data as $object => $items) {
                try {
                    $className = $supportedObjects[$object];
                    if (substr($className, 0, 1) == '\\') {
                        $className = substr($className, 1);
                    }
                    $controller = GeneralUtility::makeInstance($className);
                    $markerResult .= $controller->retrieveMarkerInfo($items, $tsConfig);
                } catch (\Exception $e) {
                    $markerResult.=$e->getMessage();
                }
            }

            $body = new Stream('php://temp', 'rw');
            $body->write($markerResult);

            return (new Response())
                ->withHeader('content-type', 'text/html; charset=utf-8')
                ->withBody($body)
                ->withStatus(200);
        }
        return $handler->handle($request);
    }

    /**
     * @param $request
     * @throws \TYPO3\CMS\Extbase\Object\Exception
     */
    protected function initTSFE($request)
    {
        if(!isset($GLOBALS['TSFE']) || !is_object($GLOBALS['TSFE']) || !$GLOBALS['TSFE'] instanceof TypoScriptFrontendController) {
            $site = $request->getAttribute('site', null);
            /** @var ObjectManager $objectManager */
            $objectManager = GeneralUtility::makeInstance(ObjectManager::class);
            $lang = $site->getDefaultLanguage();
            $siteLanguage = $objectManager->get(SiteLanguage::class, $lang->getLanguageId(), $lang->getLocale(), $lang->getBase(), []);
            /** @var TypoScriptFrontendController $TSFE */
            $TSFE = $objectManager->get(
                TypoScriptFrontendController::class,
                GeneralUtility::makeInstance(Context::class),
                $site,
                $siteLanguage,
                GeneralUtility::_GP('no_cache'),
                GeneralUtility::_GP('cHash')
            );
            $GLOBALS['TSFE'] = $TSFE;
        }
    }

}