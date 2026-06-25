import './stimulus_bootstrap.js';
import './styles/app.css'

import { Application } from '@hotwired/stimulus'
import { startStimulusApp } from '@symfony/stimulus-bundle'

const app = startStimulusApp()

import VerseCompareController  from './controllers/verse_compare_controller.js'
import SourceWordController    from './controllers/source_word_controller.js'
import DutchWordController     from './controllers/dutch_word_controller.js'
import WordLinkerController    from './controllers/word_linker_controller.js'
import PassageSelectController from './controllers/passage_select_controller.js'
import StrongsSelectController from './controllers/strongs_select_controller.js'
import NavPanelController      from './controllers/nav_panel_controller.js'
import StrongsPanelController  from './controllers/strongs_panel_controller.js'
import ThemeController         from './controllers/theme_controller.js'

app.register('verse-compare',   VerseCompareController)
app.register('source-word',     SourceWordController)
app.register('dutch-word',      DutchWordController)
app.register('word-linker',     WordLinkerController)
app.register('passage-select',  PassageSelectController)
app.register('strongs-select',  StrongsSelectController)
app.register('nav-panel',       NavPanelController)
app.register('strongs-panel',   StrongsPanelController)
app.register('theme',           ThemeController)
