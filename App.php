<?php


use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use PHPHtmlParser\Exceptions\ChildNotFoundException;
use PHPHtmlParser\Exceptions\CircularException;
use PHPHtmlParser\Exceptions\ContentLengthException;
use PHPHtmlParser\Exceptions\LogicalException;
use PHPHtmlParser\Exceptions\NotLoadedException;
use PHPHtmlParser\Exceptions\StrictException;
use Psr\Http\Message\ResponseInterface;
use PHPHtmlParser\Dom;

class App
{
    private Client  $client;
    private array $proxy = [];
    private int $proxyIndex = 0;
    private int $page = 1;
    private int $total_page = 174992;
    private string $url = 'https://postaldb.net/ru/kazakhstan/postal-code/index?page=';
    private array $links_fails = [];
    private PDO $db;
    private ?Dom $dom;
    private array $promise = [];

    public function __construct()
    {

        $this->client = new Client([
            'verify' => false, 'timeout' => 2.0,
        ]);
        try {
            $this->db = new PDO('mysql:dbname=parserKzIndex;host=127.0.0.1', 'root', 'password');
        } catch (PDOException $e) {
            echo 'Подключение не удалось: ' . $e->getMessage();
            exit;
        }
    }

    /**
     * @return void
     */
    private function loadProxy(): void
    {
        echo "LOAD PROXY\r\n";
        $data = file('socks5.txt');
        foreach ($data as $item) {
            $item = trim($item);
            if (!empty($item)) {
                echo "PROXY: {$item}\r\n";
                $this->proxy[] = $item;
            }
        }
    }

    /**
     * @return string
     */
    private function getCurrentProxy(): string
    {
        if (count($this->proxy) >= $this->proxyIndex) {
            $this->proxyIndex = 0;
        }
        return $this->proxy[$this->proxyIndex++];
    }

    /**
     * @param $proxy
     * @param false $fail
     * @return bool
     */
    private function connect($proxy, $fail = false): bool
    {
        if (!$fail) {
            $this->fails = 0;
        }

        try {
            $url = $this->url . $this->page;

            $this->promise[$url] = $this->client->getAsync($url,
//                    ['proxy' => "socks5://{$proxy}", 'timeout' => 60, 'verify' => false]
                ['verify' => false, 'timeout' => 60]
            )->then(
                function (ResponseInterface $result) use ($url) {

                    if ((int)$result->getStatusCode() === 200) {
                        echo "URL: {$url}\r\n";
                        $data = $result->getBody()->getContents();
                        return $this->saveHtml($data, $url);
                    }
                    return false;
                },
                function ($e) use ($url) {
                    $this->error($e);
                    $this->links_fails[] = $url;
                    echo "FAIL: {$url}\r\n";
                    return $this->connect($this->getCurrentProxy(), true);
                }
            );
        } catch (GuzzleException $e) {
            $this->error($e);
            echo "FAIL2: {$url}\r\n";
            return $this->connect($this->getCurrentProxy(), true);
        }
        return false;
    }


    private function error($exception): bool
    {
        $file = $exception->getFile();
        $line = $exception->getLine();
        $error = $exception->getMessage();
        return $this->db->prepare('insert into errors (file,line,error)values (?,?,?)')
            ->execute([$file, $line, $error]);
    }

    private function step1()
    {
        echo "STEP 1 - run \r\n";
        $this->loadProxy();
        echo "STEP 1 - parser \r\n";
        for ($this->page = 1; $this->page < $this->total_page; $this->page++) {
            try {
                $this->connect($this->getCurrentProxy());
            } catch (Exception $exception) {
                $this->error($exception);
            }
            if (count($this->promise) >= 10) {
                try {
                    $results = GuzzleHttp\Promise\unwrap($this->promise);
                    $this->promise = count($this->links_fails) ? $this->links_fails : [];
                    $this->links_fails = [];
                } catch (Throwable $e) {
                    $this->error($e);
                }

            }
        }

        echo "STEP 1 - end \r\n";
    }

    private function findvalue(Dom\Node\HtmlNode $htmlNode)
    {
//        if (count($htmlNode->find('dt')))
    }

    private function step2()
    {
        echo "STEP 2 - run \r\n";
        $this->dom = new Dom;
        $q = $this->db->query('select id from html');
        $q->execute();
        $ids = [];
        foreach ($q->fetchAll(PDO::FETCH_OBJ) as $item) {
            $ids[] = $item->id;
        }
        foreach ($ids as $id) {
            $q = $this->db->prepare('select * from html where id=?');
            $q->execute([$id]);
            $item = $q->fetch(PDO::FETCH_OBJ);
            try {
                $this->dom->loadStr($item->data);
                $rows = $this->dom->find('#w0 dl');
                if (!empty($rows)) {
                    foreach ($rows as $row) {
                        $fRows = new Dom;
                        $filter = preg_replace('/\<\/dd\> \<\/dd\>/', '</dd>', $row->innerHtml);
                        $filter = preg_replace('/<\/a> <dd>/', '</dd>', $filter);
                        $fRows->loadStr($filter);

                        $entity = [
                            'index_new'      => '',
                            'index_old'      => '',
                            'location_1'     => '',
                            'location_2'     => '',
                            'region_1'       => '',
                            'region_2'       => '',
                            'area_1_1'       => '',
                            'area_1_2'       => '',
                            'area_2'         => '',
                            'street_1'       => '',
                            'street_2'       => '',
                            'house_number_1' => '',
                            'house_number_2' => '',
                        ];
                        $groups = [];
                        $group_id = 0;
                        foreach ($fRows->getChildren() as $child) {
                            if ($child->getTag()->name() === 'dt') {
                                $group_id++;
                                $groups[$group_id][$child->getTag()->name()][] = $child;
                            } elseif ($child->getTag()->name() === 'dd') {
                                $groups[$group_id][$child->getTag()->name()][] = $child;
                            }
                        }
                        preg_match('/\((\d+)\)/u', $groups[1]['dd'][0]->innertext, $match);
                        $entity['index_old'] = trim($match[0], '()');
                        preg_match('/([A-z0-9]+)/u', $groups[1]['dd'][0]->innertext, $match);
                        $entity['index_new'] = trim($match[0]);
                        $location = $groups[2]['dd'];
                        $entity['location_1'] = $location[0]->find('a')->innertext;
                        if (count($location) > 1) {
                            $entity['location_2'] = $location[1]->innertext;
                        }

                        $region = $groups[3]['dd'];
                        $entity['region_1'] = $region[0]->find('a')->innertext;
                        if (count($region) > 1) {
                            $entity['region_2'] = $region[1]->innertext;
                        }

                        $area_1 = $groups[4]['dd'];
                        $area_1['area_1_1'] = $area_1[0]->find('a')->innertext;
                        if (count($area_1) > 1) {
                            $entity['area_1_2'] = $area_1[1]->innertext;
                        }

                        $area_2 = $groups[5]['dd'];
                        $area_2['area_2'] = $area_1[0]->innertext;

                    }
                }
            } catch (ChildNotFoundException $e) {
            } catch (CircularException $e) {
            } catch (ContentLengthException $e) {
            } catch (LogicalException $e) {
            } catch (StrictException $e) {
            } catch (NotLoadedException $e) {
            }


            $this->dom = null;
        }
        echo "STEP 2 - end \r\n";
    }

    private function saveHtml(string $data, string $url): bool
    {
        if ($data !== null) {
            if ($this->db->prepare('insert into html (data,link)values (?,?)')
                ->execute([$data, $url])) {
                echo "SAVE {$url}\r\n";
                return true;
            }

            echo "SKIP {$url}\r\n";
        }
        return false;
    }

    public function run(): void
    {
        ini_set('memory_limit', '10G');
        set_time_limit(0);
//        $this->step1();
        $this->step2();
    }
}