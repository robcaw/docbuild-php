<?php

namespace spec\Vivait\DocBuild;

use Doctrine\Common\Cache\Cache;
use PhpSpec\ObjectBehavior;
use Prophecy\Argument;
use Vivait\DocBuild\Exception\FileException;
use Vivait\DocBuild\Exception\TokenExpiredException;
use Vivait\DocBuild\Exception\UnauthorizedException;
use Vivait\DocBuild\Http\HttpAdapter;

class DocBuildSpec extends ObjectBehavior
{
    function it_is_initializable()
    {
        $this->shouldHaveType('Vivait\DocBuild\DocBuild');
    }

    function let(HttpAdapter $httpAdapter, Cache $cache)
    {
        $httpAdapter->setUrl('http://api.doc.build/')->shouldBeCalled();

        $cache->contains('accessToken')->willReturn(true);
        $cache->fetch('accessToken')->willReturn('myapitoken');

        $this->beConstructedWith('myid', 'mysecret', [], $httpAdapter, $cache);
    }

    function it_authorizes_if_no_token_set(HttpAdapter $httpAdapter, Cache $cache)
    {
        $cache->contains('accessToken')->willReturn(false);

        $response = ['access_token' => 'newtoken', 'expires_in' => 3600, 'token_type' => 'bearer', 'scope' => ''];
        $httpAdapter->get('oauth/token', [
            'client_id' => 'myid',
            'client_secret' => 'mysecret',
            'grant_type' => 'client_credentials'
        ])->willReturn($response);

        $httpAdapter->getResponseCode()->willReturn(200);
        $cache->save('accessToken', 'newtoken')->shouldBeCalled();

        $httpAdapter->get('documents', ['access_token' => 'newtoken'], [])->shouldBeCalled();

        $this->getDocuments();
    }


    function it_can_get_a_list_of_documents(HttpAdapter $httpAdapter)
    {
        $expected = [
            [
                'status' => 0,
                'id' => 'a1ec0371-966d-11e4-baee-08002730eb8a',
                'name' => 'Test Document 1',
                'extension' => 'docx',
            ],
            [
                'status' => 0,
                'id' => 'ee572a33-43c9-45c2-939a-009d0d48241f',
                'name' => 'Test Document 2',
                'extension' => 'docx',
            ],
        ];

        $httpAdapter->get('documents', ['access_token' => 'myapitoken'], [])->willReturn($expected);
        $this->getDocuments()->shouldReturn($expected);
    }

    function it_can_download_a_document(HttpAdapter $httpAdapter)
    {
        $id = 'a1ec0371-966d-11e4-baee-08002730eb8a';

        $httpAdapter->get('documents/' . $id . '/payload' , ['access_token' => 'myapitoken'], [])->shouldBeCalled();

        $headers = [
            'Content-Disposition' => ['attachment'],
            'filename' => ['TestDocument1.docx']
        ];

        $httpAdapter->getResponseHeaders()->willReturn($headers);

        $this->downloadDocument($id);
        $this->getHttpAdapter()->getResponseHeaders()->shouldReturn($headers);
    }

    function it_can_get_document_info(HttpAdapter $httpAdapter)
    {
        $id = 'a1ec0371-966d-11e4-baee-08002730eb8a';

        $expected = [
            'status' => 0,
            'id' => 'a1ec0371-966d-11e4-baee-08002730eb8a',
            'name' => 'Test Document 2',
            'extension' => 'docx',
        ];

        $httpAdapter->get('documents/' . $id, ['access_token' => 'myapitoken'], [])->willReturn($expected);
        $this->getDocument($id)->shouldReturn($expected);
    }

    function it_can_create_a_document_with_a_payload(HttpAdapter $httpAdapter)
    {
        $file = tempnam('/tmp', 'file');

        $expected = [
            "status" => 0,
            "id" => "a1ec0371-966d-11e4-baee-08002730eb8a",
            "name" => "Test Document 1",
            "extension" => "docx",
        ];

        $request = [
            'document[name]' => 'Test File 1',
            'document[extension]' => 'docx',
            'document[file]'=> new \SplFileObject($file),
            'access_token' => 'myapitoken',
        ];

        $httpAdapter->post('documents', $request, [])->willReturn($expected);

        $this->createDocument('Test File 1', 'docx', $file)->shouldReturn($expected);
    }

    function it_can_create_a_document_without_a_payload(HttpAdapter $httpAdapter)
    {
        $expected = [
            "status" => 0,
            "id" => "a1ec0371-966d-11e4-baee-08002730eb8a",
            "name" => "Test Document 1",
            "extension" => "docx",
        ];

        $request = [
            'document[name]' => 'Test File 1',
            'document[extension]' => 'docx',
            'access_token' => 'myapitoken',
        ];

        $httpAdapter->post('documents', $request, [])->willReturn($expected);

        $this->createDocument('Test File 1', 'docx', null)->shouldReturn($expected);
    }


    function it_can_upload_a_payload_to_an_existing_document(HttpAdapter $httpAdapter)
    {
        $file = tempnam('/tmp', 'file');

        $fileObj = new \SplFileObject($file);

        $expected = [];
        $request = [
            'document[file]' => $fileObj,
            'access_token' => 'myapitoken',
        ];

        $httpAdapter->post('documents/a1ec0371-966d-11e4-baee-08002730eb8a/payload', $request, [])->willReturn($expected);

        $this->shouldNotThrow(new FileException())->duringUploadDocument('a1ec0371-966d-11e4-baee-08002730eb8a', $file);

        $this->uploadDocument('a1ec0371-966d-11e4-baee-08002730eb8a', $file)->shouldReturn($expected);
    }

    function it_throws_an_exception_if_invalid_file_probided(HttpAdapter $httpAdapter)
    {
        $file = 'notafile';
        $this->shouldThrow(new FileException())->duringUploadDocument('a1ec0371-966d-11e4-baee-08002730eb8a', $file);
        //TODO for other file upload operations
    }

    function it_wont_throw_exception_if_valid_file_probided(HttpAdapter $httpAdapter)
    {
        $file = tempnam('/tmp', 'file');
        $this->shouldNotThrow(new FileException())->duringUploadDocument('a1ec0371-966d-11e4-baee-08002730eb8a', $file);
        //TODO for other file upload operations
    }

    function it_can_create_a_callback(HttpAdapter $httpAdapter)
    {
        $expected = [];

        $request = [
            'source' => 'a1ec0371-966d-11e4-baee-08002730eb8a',
            'url' => 'http://localhost/test/callback?id=a1ec0371-966d-11e4-baee-08002730eb8a',
            'access_token' => 'myapitoken',
        ];

        $httpAdapter->post('callback', $request, [])->willReturn($expected);

        $this->createCallback('a1ec0371-966d-11e4-baee-08002730eb8a', 'http://localhost/test/callback?id=a1ec0371-966d-11e4-baee-08002730eb8a', null)
            ->shouldReturn($expected);
    }

    function it_can_combine_a_document(HttpAdapter $httpAdapter)
    {
        $expected = [];

        $request = [
            'name' => 'Combined Document 2',
            'source' => [
                'a1ec0371-966d-11e4-baee-08002730eb8a',
                'a1ec0371-966d-11e4-baee-08002730eb8b',
            ],
            'callback' => 'http://localhost/test/callback?id=a1ec0371-966d-11e4-baee-08002730eb8a',
            'access_token' => 'myapitoken',
        ];

        $httpAdapter->post('combine', $request, [])->willReturn($expected);

        $this->combineDocument('Combined Document 2', ["a1ec0371-966d-11e4-baee-08002730eb8a", "a1ec0371-966d-11e4-baee-08002730eb8b"] , 'http://localhost/test/callback?id=a1ec0371-966d-11e4-baee-08002730eb8a')
            ->shouldReturn($expected);
    }


    function it_can_convert_a_doc_to_pdf(HttpAdapter $httpAdapter, Cache $cache)
    {
        $expected = [];

        $request = [
            'source' => 'a1ec0371-966d-11e4-baee-08002730eb8a',
            'callback' => 'http://localhost/test/callback?id=a1ec0371-966d-11e4-baee-08002730eb8a',
            'access_token' => 'myapitoken',
        ];

        $httpAdapter->post('pdf', $request, [])->willReturn($expected);

        $this->convertToPdf('a1ec0371-966d-11e4-baee-08002730eb8a', 'http://localhost/test/callback?id=a1ec0371-966d-11e4-baee-08002730eb8a')
            ->shouldReturn($expected);
    }

    function it_errors_with_invalid_credentials(HttpAdapter $httpAdapter, Cache $cache)
    {
        $this->setClientSecret('anincorrectsecret');
        $cache->contains('accessToken')->willReturn(false);

        $httpAdapter->get('oauth/token', [
            'client_id' => 'myid',
            'client_secret' => 'anincorrectsecret',
            'grant_type' => 'client_credentials'
        ])->willThrow(new UnauthorizedException());

        $httpAdapter->getResponseCode()->willReturn(401);

        $this->shouldThrow(new UnauthorizedException())->duringGetDocuments();
    }

    function it_can_authorize_the_client(HttpAdapter $httpAdapter)
    {
        $response = ['access_token' => 'myapitoken', 'expires_in' => 3600, 'token_type' => 'bearer', 'scope' => ''];
        $httpAdapter->get('oauth/token', [
            'client_id' => 'myid',
            'client_secret' => 'mysecret',
            'grant_type' => 'client_credentials'
        ])->willReturn($response);

        $httpAdapter->getResponseCode()->willReturn(200);

        $httpAdapter->get('documents', ['access_token' => 'myapitoken',], [])->willReturn([]);

        $this->getDocuments();
    }

    function it_clears_the_cache_if_exception(HttpAdapter $httpAdapter, Cache $cache)
    {
        $this->setOptions(['token_refresh' => false]);

        $cache->contains('accessToken')->willReturn(true);
        $cache->fetch('accessToken')->willReturn('expiredtoken');

        $httpAdapter->get('documents', ['access_token' => 'expiredtoken'], [])
            ->willThrow(new TokenExpiredException("The access token provided has expired."));

        $cache->delete('accessToken')->shouldBeCalled();

        $this->shouldThrow(new TokenExpiredException())->duringGetDocuments();
    }
}
