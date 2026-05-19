import './stimulus_bootstrap.js';
import './styles/app.css'

import { Application } from '@hotwired/stimulus'
import { startStimulusApp } from '@symfony/stimulus-bundle'

const app = startStimulusApp()

import VerseCompareController from './controllers/verse_compare_controller.js'
import SourceWordController    from './controllers/source_word_controller.js'
import DutchWordController     from './controllers/dutch_word_controller.js'

app.register('verse-compare', VerseCompareController)
app.register('source-word',   SourceWordController)
app.register('dutch-word',    DutchWordController)
