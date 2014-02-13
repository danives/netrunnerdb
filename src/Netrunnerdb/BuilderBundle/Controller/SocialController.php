<?php

namespace Netrunnerdb\BuilderBundle\Controller;
use Symfony\Component\Finder\Exception\AccessDeniedException;

use \DateTime;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Netrunnerdb\BuilderBundle\Entity\Deck;
use Netrunnerdb\BuilderBundle\Entity\Deckslot;
use Netrunnerdb\BuilderBundle\Entity\Decklist;
use Netrunnerdb\BuilderBundle\Entity\Decklistslot;
use Netrunnerdb\BuilderBundle\Entity\Comment;
use Netrunnerdb\UserBundle\Entity\User;
use \Michelf\Markdown;

class SocialController extends Controller {
	/*
	 * checks to see if a deck can be published in its current saved state
	 */
	public function publishAction($deck_id) {
		/* @var $em \Doctrine\ORM\EntityManager */
		$em = $this->get('doctrine')->getManager();
		
		$request = $this->getRequest();
		$deck = $em->getRepository('NetrunnerdbBuilderBundle:Deck')->find($deck_id);

		if ($this->getUser()->getId() != $deck->getUser()->getId())
			throw new UnauthorizedHttpException(
					"You don't have access to this deck.");

		$judge = $this->get('judge');
		$analyse = $judge->analyse($deck->getCards());

		if (is_string($analyse))
			throw new AccessDeniedHttpException($judge->problem($analyse));

		$new_content = json_encode($deck->getContent());
		$new_signature = md5($new_content);
		$old_decklists = $this->getDoctrine()
				->getRepository('NetrunnerdbBuilderBundle:Decklist')
				->findBy(array('signature' => $new_signature));
		foreach ($old_decklists as $decklist) {
			if (json_encode($decklist->getContent()) == $new_content) {
				return new Response($this->generateUrl('decklist_detail',array('decklist_id' => $decklist->getId(),'decklist_name' => $decklist->getPrettyName())));
			}
		}

		return new Response('');
	}

	/*
	 * creates a new decklist from a deck (publish action)
	 */
	public function newAction() {
		/* @var $em \Doctrine\ORM\EntityManager */
		$em = $this->get('doctrine')->getManager();
		
		$request = $this->getRequest();
		$deck_id = filter_var($request->request->get('deck_id'),
				FILTER_SANITIZE_NUMBER_INT);
		/* @var $deck \Netrunnerdb\BuilderBundle\Entity\Deck */
		$deck = $this->getDoctrine()
				->getRepository('NetrunnerdbBuilderBundle:Deck')
				->find($deck_id);
		if ($this->getUser()->getId() != $deck->getUser()->getId())
			throw new UnauthorizedHttpException(
					"You don't have access to this deck.");

		$judge = $this->get('judge');
		$analyse = $judge->analyse($deck->getCards());
		if (is_string($analyse)) {
			throw new AccessDeniedHttpException($judge->problem($analyse));
		}

		$new_content = json_encode($deck->getContent());
		$new_signature = md5($new_content);
		$old_decklists = $this->getDoctrine()
				->getRepository('NetrunnerdbBuilderBundle:Decklist')
				->findBy(array('signature' => $new_signature));
		foreach ($old_decklists as $decklist) {
			if (json_encode($decklist->getContent()) == $new_content) {
				throw new AccessDeniedHttpException(
						'That decklist already exists.');
			}
		}

		$name = filter_var($request->request->get('name'), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
		$name = substr($name, 0, 60);
		if(empty($name)) $name = "Untitled";
		$rawdescription = filter_var($request->request->get('description'), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
		$description = Markdown::defaultTransform($rawdescription);

		$decklist = new Decklist;
		$decklist->setName($name);
		$decklist->setPrettyname(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)));
		$decklist->setRawdescription($rawdescription);
		$decklist->setDescription($description);
		$decklist->setUser($this->getUser());
		$decklist->setCreation(new \DateTime());
		$decklist->setTs(new \DateTime());
		$decklist->setSignature($new_signature);
		$decklist->setIdentity($deck->getIdentity());
		$decklist->setFaction($deck->getIdentity()->getFaction());
		$decklist->setSide($deck->getSide());
		$decklist->setLastPack($deck->getLastPack());
		$decklist->setNbvotes(0);
		$decklist->setNbfavorites(0);
		$decklist->setNbcomments(0);
		foreach ($deck->getSlots() as $slot) {
			$card = $slot->getCard();
			$decklistslot = new Decklistslot;
			$decklistslot->setQuantity($slot->getQuantity());
			$decklistslot->setCard($card);
			$decklistslot->setDecklist($decklist);
			$decklist->getSlots()->add($decklistslot);
		}
		if(count($deck->getChildren())) {
			$decklist->setPrecedent($deck->getChildren()[0]);
		} else if($deck->getParent()) {
			$decklist->setPrecedent($deck->getParent());
		}
		$decklist->setParent($deck);

		$em->persist($decklist);
		$em->flush();

		return $this->redirect($this->generateUrl('decklist_detail',array('decklist_id' => $decklist->getId(),'decklist_name' => $decklist->getPrettyName())));

	}

	/**
	 * returns the list of decklist favorited by user
	 * @param integer $limit
	 * @return \Doctrine\DBAL\Driver\PDOStatement
	 */
	public function favorites($start = 0, $limit = 30) {
		if (!$this->getUser())
			return array();
		
		/* @var $dbh \Doctrine\DBAL\Driver\PDOConnection */
		$dbh = $this->get('doctrine')->getConnection();
		/* @var $stmt \Doctrine\DBAL\Driver\PDOStatement */ 
		$count = $dbh->executeQuery("SELECT
				count(*)
				from decklist d
				join favorite f on f.decklist_id=d.id
				where f.user_id=?", array($this->getUser()->getId()))->fetch(\PDO::FETCH_NUM)[0];

		$rows = $dbh
				->executeQuery(
						"SELECT
					d.id,
					d.name,
					d.prettyname,
					d.creation,
					d.user_id,
					u.username,
					u.faction usercolor,
					u.reputation,
					c.code,
					d.nbvotes,
					d.nbfavorites,
					d.nbcomments
					from decklist d
					join user u on d.user_id=u.id
					join card c on d.identity_id=c.id
					join favorite f on f.decklist_id=d.id
					where f.user_id=? 
					order by creation desc
					limit $start, $limit", array($this->getUser()->getId()))->fetchAll(\PDO::FETCH_ASSOC);
		
		return array("count" => $count, "decklists" => $rows);
	}

	/**
	 * returns the list of decklists published by user
	 * @param integer $limit
	 * @return \Doctrine\DBAL\Driver\PDOStatement
	 */
	public function by_author($user_id, $start = 0, $limit = 30) {
		
		/* @var $dbh \Doctrine\DBAL\Driver\PDOConnection */
		$dbh = $this->get('doctrine')->getConnection();
		/* @var $stmt \Doctrine\DBAL\Driver\PDOStatement */ 
		$count = $dbh->executeQuery("SELECT
				count(*)
				from decklist d
				where d.user_id=?", array($user_id))->fetch(\PDO::FETCH_NUM)[0];
		
		$rows = $dbh
				->executeQuery(
						"SELECT
					d.id,
					d.name,
					d.prettyname,
					d.creation,
					d.user_id,
					u.username,
					u.faction usercolor,
					u.reputation,
					c.code,
					d.nbvotes,
					d.nbfavorites,
					d.nbcomments
					from decklist d
					join user u on d.user_id=u.id
					join card c on d.identity_id=c.id
					where d.user_id=? 
					order by creation desc
					limit $start, $limit", array($user_id))->fetchAll(\PDO::FETCH_ASSOC);
		
		return array("count" => $count, "decklists" => $rows);
	}

	/**
	 * returns the list of recent decklists with large number of votes
	 * @param integer $limit
	 * @return \Doctrine\DBAL\Driver\PDOStatement
	 */
	public function popular($start = 0, $limit = 30) {
		/* @var $dbh \Doctrine\DBAL\Driver\PDOConnection */
		$dbh = $this->get('doctrine')->getConnection();
		/* @var $stmt \Doctrine\DBAL\Driver\PDOStatement */ 
		$stmt = $dbh->prepare("SELECT
				count(*)
				from decklist d");
		$stmt->execute();
		$count = $stmt->fetch(\PDO::FETCH_NUM);
		
		$rows = $dbh
				->executeQuery(
						"SELECT
					d.id,
					d.name,
					d.prettyname,
					d.creation,
					d.user_id,
					u.username,
					u.faction usercolor,
					u.reputation,
					c.code,
					d.nbvotes,
					d.nbfavorites,
					d.nbcomments,
					DATEDIFF(CURRENT_DATE, d.creation) nbjours
					from decklist d
					join user u on d.user_id=u.id
					join card c on d.identity_id=c.id
					order by nbvotes/(1+nbjours*nbjours) DESC, nbvotes desc, nbcomments desc
					limit $start, $limit")->fetchAll(\PDO::FETCH_ASSOC);
		
		return array("count" => $count[0], "decklists" => $rows);
	}

	/**
	 * returns the list of decklists with most number of votes
	 * @param integer $limit
	 * @return \Doctrine\DBAL\Driver\PDOStatement
	 */
	public function halloffame($start = 0, $limit = 30) {
		/* @var $dbh \Doctrine\DBAL\Driver\PDOConnection */
		$dbh = $this->get('doctrine')->getConnection();
		/* @var $stmt \Doctrine\DBAL\Driver\PDOStatement */
		$stmt = $dbh->prepare("SELECT
				count(*)
				from decklist d");
		$stmt->execute();
		$count = $stmt->fetch(\PDO::FETCH_NUM);
	
		$rows = $dbh
		->executeQuery(
				"SELECT
				d.id,
				d.name,
				d.prettyname,
				d.creation,
				d.user_id,
				u.username,
				u.faction usercolor,
				u.reputation,
				c.code,
				d.nbvotes,
				d.nbfavorites,
				d.nbcomments
				from decklist d
				join user u on d.user_id=u.id
				join card c on d.identity_id=c.id
				order by nbvotes desc, creation desc
				limit $start, $limit")->fetchAll(\PDO::FETCH_ASSOC);
	
		return array("count" => $count[0], "decklists" => $rows);
	}
	
	/**
	 * returns the list of decklists with large number of recent comments
	 * @param integer $limit
	 * @return \Doctrine\DBAL\Driver\PDOStatement
	 */
	public function hottopics($start = 0, $limit = 30) {
		/* @var $dbh \Doctrine\DBAL\Driver\PDOConnection */
		$dbh = $this->get('doctrine')->getConnection();
		/* @var $stmt \Doctrine\DBAL\Driver\PDOStatement */ 
		$stmt = $dbh->prepare("SELECT
				count(*)
				from decklist d");
		$stmt->execute();
		$count = $stmt->fetch(\PDO::FETCH_NUM);
		
		$rows = $dbh
				->executeQuery(
						"SELECT
				d.id,
				d.name,
				d.prettyname,
				d.creation,
				d.user_id,
				u.username,
				u.faction usercolor,
				u.reputation,
				c.code,
				d.nbvotes,
				d.nbfavorites,
				d.nbcomments,
				(select count(*) from comment where comment.decklist_id=d.id and DATEDIFF(CURRENT_DATE, comment.creation)<1) nbrecentcomments
				from decklist d
				join user u on d.user_id=u.id
				join card c on d.identity_id=c.id
				order by nbrecentcomments desc, creation desc
				limit $start, $limit")->fetchAll(\PDO::FETCH_ASSOC);
		
		return array("count" => $count[0], "decklists" => $rows);
	}

	/**
	 * returns the list of decklists of chosen faction
	 * @param integer $limit
	 * @return \Doctrine\DBAL\Driver\PDOStatement
	 */
	public function faction($faction_code, $start = 0, $limit = 30) {
		/* @var $dbh \Doctrine\DBAL\Driver\PDOConnection */
		$dbh = $this->get('doctrine')->getConnection();
		/* @var $stmt \Doctrine\DBAL\Driver\PDOStatement */ 
		$stmt = $dbh->prepare("SELECT
				count(*)
				from decklist d
				join faction f on d.faction_id=f.id
				where f.code=?");
		$stmt->execute(array($faction_code));
		$count = $stmt->fetch(\PDO::FETCH_NUM);

		$rows = $dbh
				->executeQuery(
						"SELECT
				d.id,
				d.name,
				d.prettyname,
				d.creation,
				d.user_id,
				u.username,
				u.faction usercolor,
				u.reputation,
				c.code,
				d.nbvotes,
				d.nbfavorites,
				d.nbcomments
				from decklist d
				join user u on d.user_id=u.id
				join card c on d.identity_id=c.id
				join faction f on d.faction_id=f.id
				where f.code=?
				order by creation desc
				limit $start, $limit", array($faction_code))->fetchAll(\PDO::FETCH_ASSOC);
		
		return array("count" => $count[0], "decklists" => $rows);
	}

	/**
	 * returns the list of decklists of chosen datapack
	 * @param integer $limit
	 * @return \Doctrine\DBAL\Driver\PDOStatement
	 */
	public function lastpack($pack_code, $start = 0, $limit = 30) {
		/* @var $dbh \Doctrine\DBAL\Driver\PDOConnection */
		$dbh = $this->get('doctrine')->getConnection();
		/* @var $stmt \Doctrine\DBAL\Driver\PDOStatement */ 
		$stmt = $dbh->prepare("SELECT
				count(*)
				from decklist d
				join pack p on d.last_pack_id=p.id
				where p.code=?");
		$stmt->execute(array($pack_code));
		$count = $stmt->fetch(\PDO::FETCH_NUM);

		$rows = $dbh
				->executeQuery(
						"SELECT
				d.id,
				d.name,
				d.prettyname,
				d.creation,
				d.user_id,
				u.username,
				u.faction usercolor,
				u.reputation,
				c.code,
				d.nbvotes,
				d.nbfavorites,
				d.nbcomments
				from decklist d
				join user u on d.user_id=u.id
				join card c on d.identity_id=c.id
				join pack p on d.last_pack_id=p.id
				where p.code=?
				order by creation desc
				limit $start, $limit", array($pack_code))->fetchAll(\PDO::FETCH_ASSOC);

		return array("count" => $count[0], "decklists" => $rows);
	}
	
	/**
	 * returns the list of recent decklists
	 * @param integer $limit
	 * @return \Doctrine\DBAL\Driver\PDOStatement
	 */
	public function recent($start = 0, $limit = 30) {
		/* @var $dbh \Doctrine\DBAL\Driver\PDOConnection */
		$dbh = $this->get('doctrine')->getConnection();
		/* @var $stmt \Doctrine\DBAL\Driver\PDOStatement */ 
		$stmt = $dbh->prepare("SELECT
				count(*)
				from decklist d");
		$stmt->execute();
		$count = $stmt->fetch(\PDO::FETCH_NUM);
		
		$rows = $dbh->executeQuery("SELECT
				d.id,
				d.name,
				d.prettyname,
				d.creation,
				d.user_id,
				u.username,
				u.faction usercolor,
				u.reputation,
				c.code,
				d.nbvotes,
				d.nbfavorites,
				d.nbcomments
				from decklist d
				join user u on d.user_id=u.id
				join card c on d.identity_id=c.id
				order by creation desc
				limit $start, $limit")->fetchAll(\PDO::FETCH_ASSOC);
		
		return array("count" => $count[0], "decklists" => $rows);
	}
	
	/*
	 * displays the lists of decklists
	 */
	public function listAction($type, $code = null, $page = 1) {
		$limit = 30;
		if($page<1) $page=1;
		$start = ($page-1)*$limit;
		
		switch ($type) {
		case 'recent':
			$result = $this->recent($start, $limit);
			break;
		case 'halloffame':
			$result = $this->halloffame($start, $limit);
			break;
		case 'hottopics':
			$result = $this->hottopics($start, $limit);
			break;
		case 'faction':
			$result = $this->faction($code, $start, $limit);
			break;
		case 'lastpack':
			$result = $this->lastpack($code, $start, $limit);
			break;
		case 'favorites':
			$result = $this->favorites($start, $limit);
			break;
		case 'mine':
			if (!$this->getUser())
				$result = array();
			else
				$result = $this->by_author($this->getUser()->getId(), $start, $limit);
			break;
		case 'popular':
		default:
			$result = $this->popular($start, $limit);
			break;
		}
		
		$decklists = $result['decklists'];
		$maxcount = $result['count'];
		$count = count($decklists);
		
		/* @var $user \Netrunnerdb\UserBundle\Entity\User */
		$user = $this->getUser();

		$dbh = $this->get('doctrine')->getConnection();
		$factions = $dbh
				->executeQuery(
						"SELECT
				f.name"
								. ($this->getRequest()->getLocale() == "en" ? ''
										: '_'
												. $this->getRequest()
														->getLocale())
								. " name,
				f.code
				from faction f
				order by f.side_id asc, f.name asc")->fetchAll();

		$packs = $dbh
				->executeQuery(
						"SELECT
				p.name"
								. ($this->getRequest()->getLocale() == "en" ? ''
										: '_'
												. $this->getRequest()
														->getLocale())
								. " name,
				p.code
				from pack p
				where p.released is not null
				order by p.released desc
				limit 0,5")->fetchAll();

		
		// pagination : calcul de nbpages // currpage // prevpage // nextpage
		// à partir de $start, $limit, $count, $maxcount, $page

		$currpage = $page;
		$prevpage = max(1, $currpage-1);
		$nbpages = min(10, ceil($maxcount / $limit));
		$nextpage = min($nbpages, $currpage+1);
		
		$route = $this->getRequest()->get('_route');
		
		$pages = array();
		for($page=1; $page<=$nbpages; $page++) {
			$pages[] = array(
				"numero" => $page,
				"url" => $this->generateUrl($route, array("type" => $type, "code" => $code, "page" => $page)),
				"current" => $page == $currpage,
			);
		}
		
		return $this->render(
			'NetrunnerdbBuilderBundle:Decklist:decklists.html.twig',
			array(
					'locales' => $this->renderView('NetrunnerdbCardsBundle:Default:langs.html.twig'),
					'decklists' => $decklists,
					'packs' => $packs,
					'factions' => $factions,
					'url' => $this->getRequest()->getRequestUri(),
					'route' => $route,
					'pages' => $pages,
					'prevurl' => $currpage == 1 ? null : $this->generateUrl($route, array("type" => $type, "code" => $code, "page" => $prevpage)),
					'nexturl' => $currpage == $nbpages ? null : $this->generateUrl($route, array("type" => $type, "code" => $code, "page" => $nextpage))
			));

	}

	/*
	 * displays the content of a decklist along with comments, siblings, similar, etc.
	 */
	public function viewAction($decklist_id, $decklist_name) {
		$response = new Response();
		
		$dbh = $this->get('doctrine')->getConnection();
		$rows = $dbh
				->executeQuery(
						"SELECT
				d.id,
				d.ts,
				d.name,
				d.prettyname,
				d.creation,
				d.rawdescription,
				d.description,
				d.precedent_decklist_id precedent,
				u.id user_id,
				u.username,
				u.faction usercolor,
				u.reputation,
				c.code identity_code,
				f.code faction_code,
				d.nbvotes,
				d.nbfavorites,
				d.nbcomments
				from decklist d
				join user u on d.user_id=u.id
				join card c on d.identity_id=c.id
				join faction f on d.faction_id=f.id
				where d.id=?
				", array($decklist_id))->fetchAll();

		if(empty($rows)) {
			throw new AccessDeniedException('Wrong id');
		}
		
		$decklist = $rows[0];
		$user = $this->getUser();
		$lastModified = new DateTime($decklist['ts']);
		$response->setLastModified($user && $user->getLastLogin() > $lastModified ? $user->getLastLogin() : $lastModified);
		if ($response->isNotModified($this->getRequest())) {
			return $response;
		}
		
		$comments = $dbh
				->executeQuery(
						"SELECT
				c.id,
				c.creation,
				c.user_id,
				u.username author,
				u.faction authorcolor,
				c.text
				from comment c
				join user u on c.user_id=u.id
				where c.decklist_id=?
				order by creation asc", array($decklist_id))->fetchAll();

		$cards = $dbh
				->executeQuery(
						"SELECT
				c.code card_code,
				s.quantity qty
				from decklistslot s
				join card c on s.card_id=c.id
				where s.decklist_id=?
				order by c.code asc", array($decklist_id))->fetchAll();

		$decklist['comments'] = $comments;
		$decklist['cards'] = $cards;

		$is_liked = $this->getUser() ? (boolean) $dbh
		->executeQuery(
				"SELECT
				count(*)
				from decklist d
				join vote v on v.decklist_id=d.id
				where v.user_id=?
				and d.id=?", array($this->getUser()->getId(), $decklist_id))->fetch(\PDO::FETCH_NUM)[0] : false;
		
		$is_favorite = $this->getUser() ? (boolean) $dbh
				->executeQuery(
						"SELECT
				count(*)
				from decklist d
				join favorite f on f.decklist_id=d.id
				where f.user_id=?
				and d.id=?", array($this->getUser()->getId(), $decklist_id))->fetch(\PDO::FETCH_NUM)[0] : false;
		
		$is_author = $this->getUser() ? $this->getUser()->getId() == $decklist['user_id'] : false;
		
		$similar_decklists = array();//$this->findSimilarDecklists($decklist_id, 5);

		$precedent_decklists = $dbh->executeQuery(
							"SELECT
					d.id,
					d.name,
					d.prettyname,
					d.nbvotes,
					d.nbfavorites,
					d.nbcomments
					from decklist d
					where d.id=?
					order by d.creation asc", array($decklist['precedent']))->fetchAll();

		$successor_decklists = $dbh->executeQuery(
				"SELECT
					d.id,
					d.name,
					d.prettyname,
					d.nbvotes,
					d.nbfavorites,
					d.nbcomments
					from decklist d
					where d.precedent_decklist_id=?
					order by d.creation asc", array($decklist_id))->fetchAll();
		
		return $this
				->render(
						'NetrunnerdbBuilderBundle:Decklist:decklist.html.twig',
						array(
								'locales' => $this
										->renderView(
												'NetrunnerdbCardsBundle:Default:langs.html.twig'),
								'decklist' => $decklist,
								'similar' => $similar_decklists,
								'is_liked' => $is_liked,
								'is_favorite' => $is_favorite,
								'is_author' => $is_author,
								'precedent_decklists' => $precedent_decklists,
								'successor_decklists' => $successor_decklists,
						), $response);

	}

	/*
	 * adds a decklist to a user's list of favorites
	 */
	public function favoriteAction() {
		/* @var $em \Doctrine\ORM\EntityManager */
		$em = $this->get('doctrine')->getManager();
		
		$user = $this->getUser();
		
		$request = $this->getRequest();
		$decklist_id = filter_var($request->get('id'),
				FILTER_SANITIZE_NUMBER_INT);

		/* @var $decklist \Netrunnerdb\BuilderBundle\Entity\Decklist */
		$decklist = $em->getRepository('NetrunnerdbBuilderBundle:Decklist')->find($decklist_id);
		if (!$decklist)
			throw new AccessDeniedException('Wrong id');

		$author = $decklist->getUser();
		
		$dbh = $this->get('doctrine')->getConnection();
		$is_favorite = $dbh->executeQuery(
				"SELECT
				count(*)
				from decklist d
				join favorite f on f.decklist_id=d.id
				where f.user_id=?
				and d.id=?", array($user->getId(), $decklist_id))->fetch(\PDO::FETCH_NUM)[0];
		
		if ($is_favorite) {
			$decklist->setNbfavorites($decklist->getNbfavorites() - 1);
			$user->removeFavorite($decklist);
			if ($author->getId() != $user->getId())
				$author->setReputation($author->getReputation() - 5);
		} else {
			$decklist->setNbfavorites($decklist->getNbfavorites() + 1);
			$user->addFavorite($decklist);
			$decklist->setTs(new \DateTime());
			if ($author->getId() != $user->getId())
				$author->setReputation($author->getReputation() + 5);
		}
		$this->get('doctrine')->getManager()->flush();

		return new Response(count($decklist->getFavorites()));
	}

	/*
	 * records a user's comment
	 */
	public function commentAction() {
		$user = $this->getUser();
		$request = $this->getRequest();

		$decklist_id = filter_var($request->get('id'),
				FILTER_SANITIZE_NUMBER_INT);
		$decklist = $this->getDoctrine()
				->getRepository('NetrunnerdbBuilderBundle:Decklist')
				->find($decklist_id);

		$comment_text = trim(filter_var($request->get('comment'),
				FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES));
		if($decklist && !empty($comment_text)) {
			$comment_html = Markdown::defaultTransform($comment_text);
			
			$comment = new Comment();
			$comment->setText($comment_html);
			$comment->setCreation(new DateTime());
			$comment->setAuthor($user);
			$comment->setDecklist($decklist);
			
			$this->get('doctrine')->getManager()->persist($comment);
			$decklist->setTs(new \DateTime());
			$decklist->setNbcomments($decklist->getNbcomments() + 1);
			$this->get('doctrine')->getManager()->flush();
		}

		return $this->redirect($this->generateUrl('decklist_detail',array('decklist_id' => $decklist_id,'decklist_name' => $decklist->getPrettyName())));
	}

	/*
	 * records a user's vote
	 */
	public function voteAction() {
		/* @var $em \Doctrine\ORM\EntityManager */
		$em = $this->get('doctrine')->getManager();
		
		$user = $this->getUser();
		if(!$user) throw new UnauthorizedHttpException("You must be logged in.");
		
		$request = $this->getRequest();
		$decklist_id = filter_var($request->get('id'),
				FILTER_SANITIZE_NUMBER_INT);

		$decklist = $em->getRepository('NetrunnerdbBuilderBundle:Decklist')->find($decklist_id);
		$query = $em->getRepository('NetrunnerdbBuilderBundle:Decklist')->createQueryBuilder('d')->innerJoin('d.votes', 'u')
				->where('d.id = :decklist_id')->andWhere('u.id = :user_id')
				->setParameter('decklist_id', $decklist_id)
				->setParameter('user_id', $user->getId())->getQuery();

		$result = $query->getResult();
		if (count($result))
			goto VOTE_DONE;

		$user->addVote($decklist);
		$author = $decklist->getUser();
		$author->setReputation($author->getReputation() + 1);
		$decklist->setTs(new \DateTime());
		$decklist->setNbvotes($decklist->getNbvotes() + 1);
		$this->get('doctrine')->getManager()->flush();

		VOTE_DONE: return new Response(count($decklist->getVotes()));
	}

	/*
	 * (unused) returns an ordered list of decklists similar to the one given
	 */
	public function findSimilarDecklists($decklist_id, $number) {
		$dbh = $this->get('doctrine')->getConnection();

		$list = $dbh
				->executeQuery(
						"SELECT 
    			l.id, 
    			(
    				SELECT COUNT(s.id) 
    				FROM decklistslot s 
    				WHERE (
    					s.decklist_id=l.id 
    					AND s.card_id NOT IN (
    						SELECT t.card_id 
    						FROM decklistslot t
    						WHERE t.decklist_id=?
    					)
    				)
    				OR
    				(
    					s.decklist_id=? 
    					AND s.card_id NOT IN (
    						SELECT t.card_id 
    						FROM decklistslot t
    						WHERE t.decklist_id=l.id
    					)
			    	)
    			) difference 
     			FROM decklist l
    			WHERE l.id!=?
    			ORDER BY difference ASC
    			LIMIT 0,$number",
						array($decklist_id, $decklist_id, $decklist_id))
				->fetchAll();

		$arr = array();
		foreach ($list as $item) {

			$dbh = $this->get('doctrine')->getConnection();
			$rows = $dbh
					->executeQuery(
							"SELECT
					d.id,
					d.name,
					d.prettyname,
					d.nbvotes,
					d.nbfavorites,
					d.nbcomments
					from decklist d
					where d.id=?
					", array($item["id"]))->fetchAll();

			$decklist = $rows[0];
			$arr[] = $decklist;
		}
		return $arr;
	}

	/*
	 * (unused) adds a user to a user's list of follows
	 */
	public function followAction() {
		/* @var $em \Doctrine\ORM\EntityManager */
		$em = $this->get('doctrine')->getManager();
		
		$request = $this->getRequest();
		$follow_id = $request->request->get('following');
		$user = $this->getUser();
		$following = $em->getRepository('NetrunnerdbUserBundle:User')->find($follow_id);
		$user->addFollowing($following);
		$em->flush();
		return $this->forward('NetrunnerdbBuilderBundle:Social:following');
	}

	/*
	 * (unused) displays the list of a user's follows
	 */
	public function followingAction() {
		$user = $this->getUser();
		$following = $user->getFollowing()->toArray();
		if (!count($following)) {
			return new Response('following nobody');
		}
		$qb = $this->get('doctrine')
				->getRepository('NetrunnerdbBuilderBundle:Decklist')
				->createQueryBuilder('d');
		$qb->andWhere("d.user in (?1)")->setParameter(1, $following);
		$query = $qb->getQuery();
		$rows = $query->getResult();

		return $this
				->render(
						'NetrunnerdbBuilderBundle:Decklist:decklists.html.twig',
						array('decklists' => $rows));

	}

	/*
	 * returns a text file with the content of a decklist
	 */
	public function textexportAction($decklist_id) {
		/* @var $em \Doctrine\ORM\EntityManager */
		$em = $this->get('doctrine')->getManager();
		
		/* @var $decklist \Netrunnerdb\BuilderBundle\Entity\Decklist */
		$decklist = $em->getRepository('NetrunnerdbBuilderBundle:Decklist')->find($decklist_id);

		/* @var $judge \Netrunnerdb\SocialBundle\Services\Judge */
		$judge = $this->get('judge');
		$classement = $judge->classe($decklist->getCards(), $decklist->getIdentity());

		$lines = array();
		$types = array("Event", "Hardware", "Resource", "Icebreaker",
				"Program", "Agenda", "Asset", "Upgrade", "Operation",
				"Barrier", "Code Gate", "Sentry", "ICE");

		$lines[] = $decklist->getIdentity()->getTitle() . " ("
				. $decklist->getIdentity()->getPack()->getName() . ")";
		foreach ($types as $type) {
			if (isset($classement[$type]) && $classement[$type]['qty']) {
				$lines[] = "";
				$lines[] = $type . " (" . $classement[$type]['qty'] . ")";
				foreach ($classement[$type]['slots'] as $slot) {
					$inf = "";
					for ($i = 0; $i < $slot['influence']; $i++) {
						if ($i % 5 == 0)
							$inf .= " ";
						$inf .= "•";
					}
					$lines[] = $slot['qty'] . "x " . $slot['card']->getTitle()
							. " (" . $slot['card']->getPack()->getName() . ") "
							. $inf;
				}
			}
		}
		$content = implode("\r\n", $lines);

		$name = mb_strtolower($decklist->getName());
		$name = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $name);
		$name = preg_replace('/--+/', '-', $name);

		$response = new Response();

		$response->headers->set('Content-Type', 'text/plain');
		$response->headers
				->set('Content-Disposition',
						'attachment;filename=' . $name . ".txt");

		$response->setContent($content);
		return $response;
	}

	/*
	 * returns a octgn file with the content of a decklist
	 */
	public function octgnexportAction($decklist_id) {
		/* @var $em \Doctrine\ORM\EntityManager */
		$em = $this->get('doctrine')->getManager();
		
		/* @var $decklist \Netrunnerdb\BuilderBundle\Entity\Decklist */
		$decklist = $em->getRepository('NetrunnerdbBuilderBundle:Decklist')->find($decklist_id);

		$rd = array();
		$identity = null;
		/** @var $slot Decklistslot */
		foreach ($decklist->getSlots() as $slot) {
			if ($slot->getCard()->getType()->getName() == "Identity") {
				$identity = array("index" => $slot->getCard()->getCode(),
						"name" => $slot->getCard()->getTitle());
			} else {
				$rd[] = array("index" => $slot->getCard()->getCode(),
						"name" => $slot->getCard()->getTitle(),
						"qty" => $slot->getQuantity());
			}
		}
		$name = mb_strtolower($decklist->getName());
		$name = preg_replace('/[^a-zA-Z0-9_\-]/', '-', $name);
		$name = preg_replace('/--+/', '-', $name);
		if (empty($identity)) {
			return new Response('no identity found');
		}
		return $this->octgnexport("$name.o8d", $identity, $rd, $decklist->getRawdescription());
	}

	/*
	 * does the "downloadable file" part of the export
	 */
	public function octgnexport($filename, $identity, $rd, $description) {
		$content = $this
				->renderView('NetrunnerdbBuilderBundle::octgn.xml.twig',
						array("identity" => $identity, "rd" => $rd, "description" => strip_tags($description)));

		$response = new Response();

		$response->headers->set('Content-Type', 'application/octgn');
		$response->headers
				->set('Content-Disposition', 'attachment;filename=' . $filename);

		$response->setContent($content);
		return $response;
	}

	/*
	 * displays the main page
	 */
	public function indexAction() {

		$decklists_popular = $this->popular(0, 5)['decklists'];
		$decklists_recent = $this->recent(0, 5)['decklists'];

		return $this
				->render('NetrunnerdbBuilderBundle:Default:index.html.twig',
						array(
								'locales' => $this
										->renderView(
												'NetrunnerdbCardsBundle:Default:langs.html.twig'),
								'popular' => $decklists_popular,
								'recent' => $decklists_recent,
								'url' => $this->getRequest()->getRequestUri()));
	}

	/*
	 * edits name and description of a decklist by its publisher
	 */
	public function editAction($decklist_id)
	{
		/* @var $em \Doctrine\ORM\EntityManager */
		$em = $this->get('doctrine')->getManager();
		
		$user = $this->getUser();
		if(!$user) throw new UnauthorizedHttpException(
				"You must be logged in for this operation.");
		
		$decklist = $em->getRepository('NetrunnerdbBuilderBundle:Decklist')->find($decklist_id);
		if(!$decklist || $decklist->getUser()->getId() != $user->getId()) throw new UnauthorizedHttpException(
				"You don't have access to this decklist.");
		
		$request = $this->get('request');
		$name = trim(filter_var($request->request->get('name'), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES));
		$name = substr($name, 0, 60);
		if(empty($name)) $name = "Untitled";
		$rawdescription = trim(filter_var($request->request->get('description'), FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES));
		$description = Markdown::defaultTransform($rawdescription);
		
		$decklist->setName($name);
		$decklist->setPrettyname(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)));
		$decklist->setRawdescription($rawdescription);
		$decklist->setDescription($description);
		$decklist->setTs(new \DateTime());
		$em->flush();
		
		return $this
		->redirect(
				$this
				->generateUrl('decklist_detail',
						array('decklist_id' => $decklist_id,
								'decklist_name' => $decklist
								->getPrettyName())));
	}
	
	/*
	 * displays details about a user and the list of decklists he published
	 */
	public function profileAction($user_id, $user_name, $page)
	{
		/* @var $em \Doctrine\ORM\EntityManager */
		$em = $this->get('doctrine')->getManager();
		
		/* @var $user \Netrunnerdb\UserBundle\Entity\User */
		$user = $em->getRepository('NetrunnerdbUserBundle:User')->find($user_id);
		if(!$user) throw new NotFoundHttpException("No such user.");
		
		
		$limit = 100;
		if($page<1) $page=1;
		$start = ($page-1)*$limit;
		
		$result = $this->by_author($user_id, $start, $limit);
		
		$decklists = $result['decklists'];
		$maxcount = $result['count'];
		$count = count($decklists);
		
		// pagination : calcul de nbpages // currpage // prevpage // nextpage
		// à partir de $start, $limit, $count, $maxcount, $page
		
		$currpage = $page;
		$prevpage = max(1, $currpage-1);
		$nbpages = min(10, ceil($maxcount / $limit));
		$nextpage = min($nbpages, $currpage+1);
		
		$route = $this->getRequest()->get('_route');
		
		$pages = array();
		for($page=1; $page<=$nbpages; $page++) {
			$pages[] = array(
					"numero" => $page,
					"url" => $this->generateUrl($route, array("user_id" => $user_id, "user_name" => $user_name, "page" => $page)),
					"current" => $page == $currpage,
			);
		}
		
		return $this->render('NetrunnerdbBuilderBundle:Default:profile.html.twig', array(
			'user' => $user,
			'locales' => $this->renderView('NetrunnerdbCardsBundle:Default:langs.html.twig'),
			'decklists' => $decklists,
			'url' => $this->getRequest()->getRequestUri(),
			'route' => $route,
			'pages' => $pages,
			'prevurl' => $currpage == 1 ? null : $this->generateUrl($route, array("user_id" => $user_id, "user_name" => $user_name, "page" => $prevpage)),
			'nexturl' => $currpage == $nbpages ? null : $this->generateUrl($route, array("user_id" => $user_id, "user_name" => $user_name, "page" => $nextpage))
		));
	}
	
	public function usercommentsAction($page)
	{
		/* @var $em \Doctrine\ORM\EntityManager */
		$em = $this->get('doctrine')->getManager();
		
		/* @var $user \Netrunnerdb\UserBundle\Entity\User */
		$user = $this->getUser();
		
		$limit = 100;
		if($page<1) $page=1;
		$start = ($page-1)*$limit;
		
		/* @var $dbh \Doctrine\DBAL\Driver\PDOConnection */
		$dbh = $this->get('doctrine')->getConnection();
		/* @var $stmt \Doctrine\DBAL\Driver\PDOStatement */
		$count = $dbh->executeQuery("SELECT
				count(*)
				from comment c
				where c.user_id=?", array($user->getId()))->fetch(\PDO::FETCH_NUM)[0];
		
		$comments = $dbh
		->executeQuery(
				"SELECT
				c.id,
				c.text,
				c.creation,
				d.id decklist_id,
				d.name decklist_name,
				d.prettyname decklist_prettyname
				from comment c
				join decklist d on c.decklist_id=d.id
				where c.user_id=?
				order by creation desc
				limit $start, $limit", array($user->getId()))->fetchAll(\PDO::FETCH_ASSOC);
		
		$maxcount = count($comments);
		
		// pagination : calcul de nbpages // currpage // prevpage // nextpage
		// à partir de $start, $limit, $count, $maxcount, $page
		
		$currpage = $page;
		$prevpage = max(1, $currpage-1);
		$nbpages = min(10, ceil($maxcount / $limit));
		$nextpage = min($nbpages, $currpage+1);
		
		$route = $this->getRequest()->get('_route');
		
		$pages = array();
		for($page=1; $page<=$nbpages; $page++) {
			$pages[] = array(
					"numero" => $page,
					"url" => $this->generateUrl($route, array("page" => $page)),
					"current" => $page == $currpage,
			);
		}
		
		return $this->render('NetrunnerdbBuilderBundle:Default:usercomments.html.twig', array(
			'user' => $user,
			'locales' => $this->renderView('NetrunnerdbCardsBundle:Default:langs.html.twig'),
			'comments' => $comments,
			'url' => $this->getRequest()->getRequestUri(),
			'route' => $route,
			'pages' => $pages,
			'prevurl' => $currpage == 1 ? null : $this->generateUrl($route, array("page" => $prevpage)),
			'nexturl' => $currpage == $nbpages ? null : $this->generateUrl($route, array("page" => $nextpage))
		));
	}
	
	public function commentsAction($page)
	{

		$limit = 100;
		if($page<1) $page=1;
		$start = ($page-1)*$limit;
		
		/* @var $dbh \Doctrine\DBAL\Driver\PDOConnection */
		$dbh = $this->get('doctrine')->getConnection();
		/* @var $stmt \Doctrine\DBAL\Driver\PDOStatement */
		$maxcount = $dbh->executeQuery("SELECT
				count(*)
				from comment c", array())->fetch(\PDO::FETCH_NUM)[0];
		
		$comments = $dbh
		->executeQuery(
				"SELECT
				c.id,
				c.text,
				c.creation,
				d.id decklist_id,
				d.name decklist_name,
				d.prettyname decklist_prettyname,
				u.id user_id,
				u.username author
				from comment c
				join decklist d on c.decklist_id=d.id
				join user u on c.user_id=u.id
				order by creation desc
				limit $start, $limit", array())->fetchAll(\PDO::FETCH_ASSOC);
		
		$count = count($comments);
		
		// pagination : calcul de nbpages // currpage // prevpage // nextpage
		// à partir de $start, $limit, $count, $maxcount, $page
		
		$currpage = $page;
		$prevpage = max(1, $currpage-1);
		$nbpages = min(10, ceil($maxcount / $limit));
		$nextpage = min($nbpages, $currpage+1);
		
		$route = $this->getRequest()->get('_route');
		
		$pages = array();
		for($page=1; $page<=$nbpages; $page++) {
			$pages[] = array(
					"numero" => $page,
					"url" => $this->generateUrl($route, array("page" => $page)),
					"current" => $page == $currpage,
			);
		}
		
		return $this->render('NetrunnerdbBuilderBundle:Default:allcomments.html.twig', array(
				'locales' => $this->renderView('NetrunnerdbCardsBundle:Default:langs.html.twig'),
				'comments' => $comments,
				'url' => $this->getRequest()->getRequestUri(),
				'route' => $route,
				'pages' => $pages,
				'prevurl' => $currpage == 1 ? null : $this->generateUrl($route, array("page" => $prevpage)),
				'nexturl' => $currpage == $nbpages ? null : $this->generateUrl($route, array("page" => $nextpage))
		));
		
	}
	
	public function apidecklistAction($decklist_id)
	{
		$response = new Response();
		$response->setPublic();
		$response->setMaxAge(600);
		$response->headers->add(array('Access-Control-Allow-Origin' => '*'));
		
		$jsonp = $this->getRequest()->query->get('jsonp');
		$locale = $this->getRequest()->query->get('_locale');
		if(isset($locale)) $this->getRequest()->setLocale($locale);
		
		$dbh = $this->get('doctrine')->getConnection();
		$rows = $dbh
		->executeQuery(
				"SELECT
				d.id,
				d.ts,
				d.name,
				d.creation,
				d.description,
				u.username
				from decklist d
				join user u on d.user_id=u.id
				where d.id=?
				", array($decklist_id))->fetchAll();
		
		if(empty($rows)) {
			throw new AccessDeniedException('Wrong id');
		}
		
		$decklist = $rows[0];
		$decklist['id'] = intval($decklist['id']);
		
		$lastModified = new DateTime($decklist['ts']);
		$response->setLastModified($lastModified);
		if ($response->isNotModified($this->getRequest())) {
			return $response;
		}
		unset($decklist['ts']);
		
		$cards = $dbh
		->executeQuery(
				"SELECT
				c.code card_code,
				s.quantity qty
				from decklistslot s
				join card c on s.card_id=c.id
				where s.decklist_id=?
				order by c.code asc", array($decklist_id))->fetchAll();
		
		$decklist['cards'] = array();
		foreach($cards as $card) {
			$decklist['cards'][$card['card_code']] = intval($card['qty']);
		}
		
		$content = json_encode($decklist);
		if(isset($jsonp))
		{
			$content = "$jsonp($content)";
			$response->headers->set('Content-Type', 'application/javascript');
		} else 
		{
			$response->headers->set('Content-Type', 'application/json');
		}
	
		$response->setContent($content);
		return $response;
	}
}
