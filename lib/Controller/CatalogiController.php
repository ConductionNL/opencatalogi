<?php

namespace OCA\OpenCatalog\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

class CatalogiController extends Controller
{
    const TEST_ARRAY = [
        "5137a1e5-b54d-43ad-abd1-4b5bff5fcd3f" => [
            "id" => "5137a1e5-b54d-43ad-abd1-4b5bff5fcd3f",
            "name" => "Github",
            "summary" => "summary for one"
        ],
        "4c3edd34-a90d-4d2a-8894-adb5836ecde8" => [
            "id" => "4c3edd34-a90d-4d2a-8894-adb5836ecde8",
            "name" => "Gitlab",
            "summary" => "summary for two"
        ],
        "15551d6f-44e3-43f3-a9d2-59e583c91eb0" => [
            "id" => "15551d6f-44e3-43f3-a9d2-59e583c91eb0",
            "name" => "Woo",
            "summary" => "summary for two"
        ],
        "0a3a0ffb-dc03-4aae-b207-0ed1502e60da" => [
            "id" => "0a3a0ffb-dc03-4aae-b207-0ed1502e60da",
            "name" => "Decat",
            "summary" => "summary for two"
        ]
    ];

    public function __construct($appName, IRequest $request)
    {
        parent::__construct($appName, $request);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function page(?string $getParameter)
    {
        // The TemplateResponse loads the 'main.php'
        // defined in our app's 'templates' folder.
        // We pass the $getParameter variable to the template
        // so that the value is accessible in the template.
        return new TemplateResponse(
            //Application::APP_ID,
            'opencatalog',
            'CatalogiIndex',
            []
        );
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        $results = ["results" => self::TEST_ARRAY];
        return new JSONResponse($results);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function show(string $id): JSONResponse
    {
        $result = self::TEST_ARRAY[$id];
        return new JSONResponse($result);
    }


    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function create(): JSONResponse
    {
        // get post from requests
        return new JSONResponse([]);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function update(string $id): JSONResponse
    {
        $result = self::TEST_ARRAY[$id];
        return new JSONResponse($result);
    }

    /**
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function destroy(string $id): JSONResponse
    {
        return new JSONResponse([]);
    }
}