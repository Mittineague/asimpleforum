<?php

namespace Controller;

class TopicController extends Controller
{
	public function __construct(\Silex\Application $app)
	{
		$this->app = $app;
		$this->init();
	}

	public function index ($topic_name, $topic_id, $page = 1)
	{
		$topic_id = (int) $topic_id;

		if (!$topic_id)
		{
			$this->app->redirect('/');
		}

		$topic = $this->app['topic']->find_by_id($topic_id);

		if (!$topic)
		{
			$this->app->redirect('/');
		}

		$forum = $this->app['forum']->find_by_id($topic['forum']);

		return $this->app['twig']->render('Topic/index.twig', array(
			'title' 			=> $topic['name'],
			'section'			=> 'forums',
			'forum'				=> $forum,
			'topic'				=> $topic,
			'page'				=> $page
		) + $this->extras);
	}

	public function newest ()
	{
		$topics = $this->app['topic']->find_recent(10);

		return $this->app['twig']->render('Topic/newest.twig', array(
			'title' 			=> 'Newest Topics',
			'section'			=> 'new-topics',
			'topics'			=> $topics
		) + $this->extras);
	}
}