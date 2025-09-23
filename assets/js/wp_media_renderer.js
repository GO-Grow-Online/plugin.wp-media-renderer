/**
 * This script was converted in JavaScript by gemini and originally produces by Julien Scheen in Jquery.
 * 
 * Main function to initialize deferred video loading.
 * It finds all video wrappers with the 'defer' class and calls the defer_video function for each.
 */

function init_defer_video() {
  const videoWrappers = document.querySelectorAll('.vid-wrap');

  if (videoWrappers.length) {
    videoWrappers.forEach(function(wrapper) {
      const video = wrapper.querySelector('.vid-wrap__video');
      if (video) {
        defer_video(video, wrapper);
      }
    });
  }
}

/**
 * Activates custom video controls for a given video element.
 * It sets up event listeners for the play/pause, stop, mute, fullscreen buttons, and the progress bar.
 *
 * @param {HTMLElement} videoWrapper - The main video wrapper element.
 */
function activate_video_controls(videoWrapper) {

  const videoControls = videoWrapper.querySelector('.vid-wrap__controls');

  if (videoControls) {
    const video = videoWrapper.querySelector('.vid-wrap__video');
    
    // Get all control buttons and elements
    const playpause = videoControls.querySelector('.vid-wrap__controls__playpause');
    const stop = videoControls.querySelector('.vid-wrap__controls__stop');
    const mute = videoControls.querySelector('.vid-wrap__controls__mute');
    const progress = videoControls.querySelector('.vid-wrap__controls__progress');
    const progressBar = videoControls.querySelector('.vid-wrap__controls__progress progress');
    const fullscreen = videoWrapper.querySelector('.vid-wrap__controls__fs');

    // Remove the 'unTouched' class on the first interaction
    playpause.addEventListener('click', function() {
      videoWrapper.classList.remove('vid-wrap--unTouched');
    });

    // Check for progress bar support and set state if needed
    const supportsProgress = progressBar && 'max' in progressBar;
    if (!supportsProgress) {
      progress.dataset.state = 'fake';
    }

    // Update the progress bar as the video plays
    video.addEventListener('timeupdate', function() {
      const currentTime = video.currentTime;
      const duration = video.duration;
      if (isFinite(duration)) {
        videoWrapper.classList.remove('vid-wrap--progress-loading');
        const progressValue = currentTime / duration;
        progressBar.value = progressValue;
      }
    });

    // Handle fullscreen mode toggling
    function handleFullscreen() {
      if (document.fullscreenElement) {
        document.exitFullscreen();
      } else {
        video.requestFullscreen();
      }
    }

    // Change the state of control buttons (play/pause, mute/unmute)
    function changeButtonState(type) {
      if (type === 'playpause') {
        const state = video.paused || video.ended ? 'play' : 'pause';
        playpause.dataset.state = state;
      } else if (type === 'mute') {
        const state = video.muted ? 'unmute' : 'mute';
        mute.dataset.state = state;
      }
    }

    // Event listeners for video controls
    if (playpause) {
      playpause.addEventListener('click', function() {
        if (video.paused || video.ended) {
          video.play();
        } else {
          video.pause();
        }
      });
    }

    if (stop) {
      stop.addEventListener('click', function() {
        video.pause();
        video.currentTime = 0;
      });
    }

    if (mute) {
      mute.addEventListener('click', function() {
        video.muted = !video.muted;
        changeButtonState('mute');
      });
    }

    progress.addEventListener('click', function(e) {
      const rect = progress.getBoundingClientRect();
      const pos = (e.pageX - rect.left) / rect.width;
      const duration = video.duration;
      
      if (isFinite(duration)) {
        video.currentTime = pos * duration;
      }
    });

    // Event listeners for video element state changes
    video.addEventListener('play', () => changeButtonState('playpause'));
    video.addEventListener('pause', () => changeButtonState('playpause'));
    video.addEventListener('loadedmetadata', () => changeButtonState('mute'));
    
    // Fullscreen button event listener
    if (fullscreen) {
      fullscreen.addEventListener('click', handleFullscreen);
    }

    // If video is on autoplay, chamgne button state to "playing"
    if(video.classList.contains('vid-wrap--autoplay')) { changeButtonState('playpause');; }
  }
}

/**
 * Handles the deferred loading of video sources.
 * It checks the media queries of <source> tags and loads the correct video source.
 *
 * @param {HTMLVideoElement} video - The video element to load.
 * @param {HTMLElement} wrapper - The main video wrapper.
 */
function defer_video(video, wrapper) {
  if (video) {
    const sources = video.querySelectorAll('source');

    sources.forEach(function(source) {
      const mediaQuery = source.getAttribute('media');
      
      // Check if media query matches or if none is specified
      if (!mediaQuery || window.matchMedia(mediaQuery).matches) {
        const video_url = source.getAttribute('data-src');

        // Use the Fetch API to make a more modern request
        fetch(video_url)
          .then(response => {
            if (response.ok) {
              source.setAttribute('src', video_url);
              video.load();
              
              video.addEventListener('loadeddata', function onLoadedData() {
                wrapper.classList.remove('vid-wrap--loading');
                activate_video_controls(wrapper);
                video.removeEventListener('loadeddata', onLoadedData);
              });
            }
          })
          .catch(error => console.error('Error fetching video source:', error));
      }
    });
  }

}

document.addEventListener('DOMContentLoaded', function() {
  if (document.querySelector('.vid-wrap')) {
    init_defer_video(); 
  }
});