/**
 * Insight LATAM — scripts personalizados del sitio.
 *
 * @author Ariel Geovanny Andino Chinchilla
 * @see    https://github.com/andino1234
 */
$(document).ready(function(){
	"use strict";

	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var isMobile = window.matchMedia && window.matchMedia('(max-width: 991px)').matches;
	var scrollTimer = null;
	var $window = $(window);

	/*==================================
* Author        : "ThemeSINE"
* Template Name : Zombiz HTML Template
* Version       : 1.0
==================================== */

	/*=========== TABLE OF CONTENTS ===========
1. Scroll To Top 
2. hcsticky 
3. Counter
4. owl carousel
5. vedio player
6. animation support
======================================*/

	// 1. Scroll To Top
	$window.on('scroll', function () {
		if (scrollTimer) {
			return;
		}
		scrollTimer = window.requestAnimationFrame(function () {
			var shouldShow = $window.scrollTop() > 600;
			$('.return-to-top').stop(true, true)[shouldShow ? 'fadeIn' : 'fadeOut']();
			scrollTimer = null;
		});
	});

	$('.return-to-top').on('click', function(){
		$('html, body').animate({ scrollTop: 0 }, reduceMotion ? 0 : 900);
		return false;
	});

	// 2 . hcsticky
	if ($('#menu').length && !isMobile && $.fn.hcSticky) {
		$('#menu').hcSticky();
	}

	// 3. counter
	if ($('.counter').length && $.fn.counterUp) {
		$window.on('load', function(){
			$('.counter').counterUp({
				delay: reduceMotion ? 0 : 16,
				time: reduceMotion ? 0 : 1800
			});
		});
	}

	// 4. owl carousel
	var owl = $('.team-carousel');
	if (owl.length && $.fn.owlCarousel) {
		owl.owlCarousel({
			items:4,
			margin:0,
			loop:!isMobile,
			autoplay:!reduceMotion && !isMobile,
			smartSpeed: reduceMotion ? 0 : 500,
			dots:false,
			autoplayHoverPause:true,
			responsiveClass:true,
			responsive:{0:{items:1},640:{items:2},768:{items:3},992:{items:4}}
		});
	}

	if ($('#client').length && $.fn.owlCarousel) {
		$('#client').owlCarousel({
			items:5,
			loop:!isMobile,
			smartSpeed: reduceMotion ? 0 : 700,
			autoplay:!reduceMotion && !isMobile,
			dots:false,
			autoplayHoverPause:true,
			responsive:{0:{items:2},415:{items:2},600:{items:3},1000:{items:5}}
		});
	}

	$('.play').on('click', function(){
		owl.trigger('play.owl.autoplay',[1000]);
	});
	$('.stop').on('click', function(){
		owl.trigger('stop.owl.autoplay');
	});

	if ($('#testemonial-carousel').length && $.fn.owlCarousel) {
		$('#testemonial-carousel').owlCarousel({
			items: 1,
			autoplay: !reduceMotion && !isMobile,
			loop: true,
			dots:true,
			mouseDrag:true,
			nav:false,
			smartSpeed: reduceMotion ? 0 : 700,
			transitionStyle:'fade',
			animateIn: reduceMotion ? false : 'fadeIn',
			animateOut: reduceMotion ? false : 'fadeOutLeft'
		});
	}

	// 5. vedio player
	if ($('.vedio-play-icon').length && $.fn.magnificPopup) {
		$('.vedio-play-icon').magnificPopup({ type:'video' });
	}

	// 6. animation support
	$window.on('load', function(){
		if (reduceMotion || isMobile) {
			$('.single-slide-item-content h2, .single-slide-item-content p, .single-slide-item-content button').removeClass('animated fadeInUp fadeInLeft').css({'opacity':'1'});
			return;
		}
		$('.single-slide-item-content h2, .single-slide-item-content p').removeClass('animated fadeInUp').css({'opacity':'0'});
		$('.single-slide-item-content button').removeClass('animated fadeInLeft').css({'opacity':'0'});
		window.requestAnimationFrame(function(){
			$('.single-slide-item-content h2, .single-slide-item-content p').addClass('animated fadeInUp').css({'opacity':'1'});
			$('.single-slide-item-content button').addClass('animated fadeInLeft').css({'opacity':'1'});
		});
	});

	if (!window.openModal) {
		window.openModal = function(e, imgSrc) {
			var modalImage = document.getElementById('modalImage');
			var imageModal = document.getElementById('imageModal');
			if (!modalImage || !imageModal) return;
			if (e && e.preventDefault) e.preventDefault();
			modalImage.src = imgSrc;
			imageModal.style.display = 'flex';
		};
	}

	if (!window.closeModal) {
		window.closeModal = function() {
			var imageModal = document.getElementById('imageModal');
			if (!imageModal) return;
			imageModal.style.display = 'none';
		};
	}
});
