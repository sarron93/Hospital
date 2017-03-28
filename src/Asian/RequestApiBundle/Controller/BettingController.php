<?php
/**
 * Created by PhpStorm.
 * User: marmelad
 * Date: 29.01.2017
 * Time: 15:20
 */

namespace Asian\RequestApiBundle\Controller;

use Asian\UserBundle\Helper\Data;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\BrowserKit\Response;
use Symfony\Component\Config\Definition\Exception\Exception;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Asian\RequestApiBundle\Model\Cache;
use Unirest;


class BettingController extends Controller
{
	/**
	 * @Route("getFeeds/")
	 * @Method("GET")
	 */
	public function getFeedsAction(Request $request)
	{
		try {
			$user = $this->get('fos_user.user_manager')
				->findUserByUsername($request->query->get('username'));

			if(!$user->checkUser($request->headers->get('token'))) {
				throw new Exception();
			}

			$memcache = new Cache();
			$feeds = $memcache->getParam('feeds_live');
			if (!$feeds) {
				return $this->json(['Code' => '-1']);
			}
			return $this->json($feeds);

		} catch (Exception $e) {
			throw new HttpException(400, 'Invalid Data');
		}
	}

	/**
	 * @Route("getLeagues/")
	 * @Method("GET")
	 */
	public function getLeaguesAction(Request $request)
	{
		try{
			$helper = new Data();

			$user = $this->get('fos_user.user_manager')
				->findUserByUsername($request->query->get('username'));

			if(!$user->checkUser($request->headers->get('token'))) {
				throw new Exception();
			}

			$bookies = $request->query->get('bookies') ? $request->query->get('bookies') : 'ALL';
			$sports = $request->query->get('sports') ? $request->query->get('sports') : 1;
			$marketTypeId = $request->query->get('marketTypeId') ? $request->query->get('marketTypeId') : '0';

			$apiUser = $user->getApiUser();

			$headers = [
				'AOToken' => $apiUser->getAOToken(),
				'Accept' => $request->headers->get('accept')
			];

			$query = [
				'bookies' => $bookies,
				'sportsType' => $sports,
				'marketTypeId' => $marketTypeId,
			];

			$response = Unirest\Request::get($helper->getApiLeaguesUrl(), $headers, $query);
			if ($response->code !== 200) {
				throw new HttpException($response->code, 'Response Error');
			}

			return $this->json($response->body);
		} catch (Exception $e) {
			throw new HttpException('400', 'Invalid Data');
		}
	}

	/**
	 * @Route("getPlacement/")
	 * @Method("GET")
	 */
	public function getPlacementInfoAction(Request $request)
	{
		try {
			$helper = new Data();
			$user = $this->get('fos_user.user_manager')->findUserByUsername($request->query->get('username'));
			if (!$user->checkUser($request->headers->get('token'))) {
				throw new Exception('Invalid user data');
			}

			$apiUser = $user->getApiUser();

			$headers = [
				'AOToken' => $apiUser->getAOToken(),
				'Accept' => $request->headers->get('accept'),
				'Content-Type' => 'application/json',
			];

			$matchId = $request->query->get('match_id');
			$isFulltime = $request->query->get('is_full_time');
			$gameId = $request->query->get('game_id');
			$gameType = $request->query->get('game_type');
			$oddsName = $request->query->get('odds_name');
			$oddsFormat = $request->query->get('odds_format') ?: '00';
			$bookies = $request->query->get('bookies');
			$marketTypeId = $request->query->get('market_type_id');
			$sportsType = $request->query->get('sports_type') ?: 0;
			$getParams = [
				'matchId' => $matchId,
				'isFullTime' => $isFulltime,
				'gameId' => $gameId,
				'gameType' => $gameType,
				'oddsName' => $oddsName,
				'oddsFormat' => $oddsFormat,
			];
			$url = $helper->getApiPlacementInfo() . '?';
			$params = http_build_query($getParams);

			$url .= $params;

			$data = [
				'GameId' => $gameId,
				'GameType' => $gameType,
				'IsFullTime' => $isFulltime,
				'Bookies' => $bookies,
				'MarketTypeId' => $marketTypeId,
				'OddsFormat' => $oddsFormat,
				'OddsName' => $oddsName,
				"SportsType" => $sportsType
			];
			$body = Unirest\Request\Body::Json($data);

			$response = Unirest\Request::post($url, $headers, $body);


			if ($response->code != 200) {
				throw  new HttpException($response->code, 'Response error code');
			}

			if ($response->body->Code < 0) {
				throw new Exception($response->body->Message);
			}

			$result = $response->body->Result->OddsPlacementData[0];

			if ($result->Odds < $request->query->get('odds')) {
				return $this->json([
					'code' => 0 ,
					'message' => 'Коэффициент упал ниже ожидаемого']);
			}

			$amount = $result->MinimumAmount;
			$oddPlacementId = $result->OddPlacementId;
			$bookieOdds = $bookies . ':' . $result->Odds;

			$postParams = [
				'IsFullTime' => $isFulltime,
				'MarketTypeId' => $marketTypeId,
				'PlaceBetId' => $oddPlacementId,
				'GameId' => $gameId,
				'GameType' => $gameType,
				'OddsName' => $oddsName,
				'OddsFormat' => $oddsFormat,
				'BookieOdds' => $bookieOdds,
				'SportsType' => $sportsType,
				'Amount' => $amount,
			];

			$placeBetResponce = $this->_placeBetAction($postParams, $headers);

			if ($placeBetResponce->code != 200) {
				throw new HttpException($response->code, 'place bet request error code');
			}

			if ($placeBetResponce->body->Code < 0) {
				throw new Exception($response->body->Message);
			}

			return $this->json($placeBetResponce->body);
		} catch (Exception $e) {
			throw new HttpException('400', $e->getMessage());
		}
	}

	protected function _placeBetAction($postParams, $headers)
	{
		$helper = new Data();
		$url = $helper->getPlaceBet();
		$body = Unirest\Request\Body::Json($postParams);

		$response = Unirest\Request::post($url, $headers, $body);
		return $response;
	}

}