<?php
session_start();
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>InternBoot Level Assessment</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family:
                Arial,
                Helvetica,
                sans-serif;
            background: #f4f6f9;
            color: #1f2937;
        }

        /* =========================
           HEADER
        ========================= */

        .exam-header {
            position: sticky;
            top: 0;
            z-index: 100;

            height: 70px;

            background: #ffffff;

            border-bottom: 1px solid #e5e7eb;

            display: flex;
            align-items: center;
            justify-content: space-between;

            padding: 0 28px;
        }

        .brand {
            font-size: 21px;
            font-weight: 700;
            color: #111827;
        }

        .timer-container {
            display: flex;
            align-items: center;
            gap: 8px;

            font-weight: 700;
        }

        .timer {
            min-width: 95px;

            text-align: center;

            padding: 9px 14px;

            border-radius: 8px;

            background: #111827;
            color: #ffffff;

            font-size: 18px;
            letter-spacing: 1px;
        }

        .timer.warning {
            background: #b45309;
        }

        .timer.danger {
            background: #dc2626;
        }

        /* =========================
           MAIN LAYOUT
        ========================= */

        .page {
            max-width: 1400px;

            margin: 0 auto;

            padding: 25px;

            display: grid;

            grid-template-columns:
                minmax(0, 1fr)
                330px;

            gap: 24px;

            align-items: start;
        }

        /* =========================
           QUESTION SECTION
        ========================= */

        .question-card {
            background: #ffffff;

            border-radius: 14px;

            padding: 30px;

            box-shadow:
                0 4px 18px rgba(0, 0, 0, 0.06);
        }

        .question-meta {
            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 22px;
        }

        .question-number {
            font-size: 16px;

            font-weight: 700;

            color: #2563eb;
        }

        .difficulty {
            font-size: 13px;

            padding: 5px 10px;

            border-radius: 20px;

            background: #eef2ff;

            color: #4338ca;

            text-transform: capitalize;
        }

        .question-text {
            font-size: 21px;

            line-height: 1.6;

            font-weight: 600;

            margin-bottom: 28px;
        }

        /* =========================
           OPTIONS
        ========================= */

        .options {
            display: flex;

            flex-direction: column;

            gap: 13px;
        }

        .option {
            position: relative;

            display: flex;

            align-items: flex-start;

            gap: 12px;

            padding: 16px;

            border: 1px solid #d1d5db;

            border-radius: 10px;

            cursor: pointer;

            transition:
                background 0.15s,
                border 0.15s;
        }

        .option:hover {
            background: #f8fafc;
        }

        .option.selected {
            border-color: #2563eb;

            background: #eff6ff;
        }

        .option input {
            margin-top: 4px;

            accent-color: #2563eb;
        }

        .option-text {
            line-height: 1.5;
        }

        /* =========================
           NAVIGATION
        ========================= */

        .navigation {
            display: flex;

            justify-content: space-between;

            gap: 12px;

            margin-top: 25px;
        }

        button {
            border: none;

            border-radius: 8px;

            padding: 12px 20px;

            font-size: 14px;

            font-weight: 700;

            cursor: pointer;

            transition:
                opacity 0.15s,
                transform 0.1s;
        }

        button:active {
            transform: scale(0.98);
        }

        button:disabled {
            opacity: 0.45;

            cursor: not-allowed;
        }

        .btn-secondary {
            background: #e5e7eb;

            color: #111827;
        }

        .btn-primary {
            background: #2563eb;

            color: #ffffff;
        }

        .btn-submit {
            background: #dc2626;

            color: #ffffff;
        }

        /* =========================
           QUESTION PALETTE
        ========================= */

        .navigator {
            position: sticky;

            top: 95px;

            background: #ffffff;

            border-radius: 14px;

            padding: 22px;

            box-shadow:
                0 4px 18px rgba(0, 0, 0, 0.06);
        }

        .navigator-title {
            font-size: 17px;

            font-weight: 700;

            margin-bottom: 15px;
        }

        .navigator-info {
            font-size: 13px;

            color: #6b7280;

            margin-bottom: 18px;
        }

        .question-grid {
            display: grid;

            grid-template-columns:
                repeat(5, 1fr);

            gap: 8px;
        }

        .question-btn {
            height: 42px;

            padding: 0;

            border-radius: 7px;

            background: #f3f4f6;

            border: 1px solid #d1d5db;

            color: #374151;

            font-size: 13px;
        }

        .question-btn:hover {
            background: #e5e7eb;
        }

        .question-btn.current {
            background: #2563eb;

            border-color: #2563eb;

            color: #ffffff;
        }

        .question-btn.answered {
            background: #dcfce7;

            border-color: #86efac;

            color: #166534;
        }

        .question-btn.answered.current {
            background: #2563eb;

            border-color: #2563eb;

            color: #ffffff;
        }

        /* =========================
           LEGEND
        ========================= */

        .legend {
            display: flex;

            flex-direction: column;

            gap: 9px;

            margin-top: 20px;

            padding-top: 18px;

            border-top: 1px solid #e5e7eb;

            font-size: 12px;

            color: #4b5563;
        }

        .legend-item {
            display: flex;

            align-items: center;

            gap: 8px;
        }

        .legend-box {
            width: 14px;

            height: 14px;

            border-radius: 3px;

            border: 1px solid #d1d5db;
        }

        .legend-current {
            background: #2563eb;

            border-color: #2563eb;
        }

        .legend-answered {
            background: #dcfce7;

            border-color: #86efac;
        }

        .legend-unanswered {
            background: #f3f4f6;
        }

        /* =========================
           SUBMIT AREA
        ========================= */

        .submit-area {
            margin-top: 20px;

            padding-top: 18px;

            border-top: 1px solid #e5e7eb;
        }

        .submit-area button {
            width: 100%;
        }

        /* =========================
           LOADING
        ========================= */

        .loading {
            max-width: 500px;

            margin: 100px auto;

            text-align: center;

            color: #6b7280;

            font-size: 17px;
        }

        /* =========================
           ERROR
        ========================= */

        .error {
            max-width: 600px;

            margin: 100px auto;

            padding: 25px;

            background: #fef2f2;

            border: 1px solid #fecaca;

            border-radius: 10px;

            color: #b91c1c;

            text-align: center;
        }

        /* =========================
           SAVE INDICATOR
        ========================= */

        .save-status {
            margin-top: 15px;

            font-size: 13px;

            color: #6b7280;

            min-height: 20px;
        }

        .save-status.success {
            color: #15803d;
        }

        .save-status.error {
            margin: 15px 0 0;

            padding: 0;

            border: none;

            background: transparent;

            text-align: left;

            color: #dc2626;
        }

        /* =========================
           RESPONSIVE
        ========================= */

        @media (max-width: 900px) {

            .page {
                grid-template-columns: 1fr;
            }

            .navigator {
                position: static;

                order: 2;
            }

            .question-grid {
                grid-template-columns:
                    repeat(10, 1fr);
            }

        }

        @media (max-width: 600px) {

            .exam-header {
                padding: 0 15px;

                height: 62px;
            }

            .brand {
                font-size: 17px;
            }

            .timer {
                font-size: 15px;

                min-width: 78px;

                padding: 8px 9px;
            }

            .page {
                padding: 12px;
            }

            .question-card {
                padding: 20px;
            }

            .question-text {
                font-size: 18px;
            }

            .navigation {
                flex-wrap: wrap;
            }

            .navigation button {
                flex: 1;
            }

            .question-grid {
                grid-template-columns:
                    repeat(5, 1fr);
            }

        }

    </style>

</head>

<body>

<header class="exam-header">

    <div class="brand">
        InternBoot Level Assessment
    </div>

    <div class="timer-container">

        <span>Time Left</span>

        <span
            id="timer"
            class="timer"
        >
            --:--
        </span>

    </div>

</header>


<div
    id="loading"
    class="loading"
>
    <div>Loading assessment...</div>

    <button
        id="fullscreenBtn"
        class="btn-primary"
        style="margin-top:20px;"
        onclick="enterFullscreen()"
    >
        Enter Fullscreen & Start Assessment
    </button>
</div>


<div
    id="error"
    class="error"
    style="display:none;"
></div>


<main
    id="exam"
    class="page"
    style="display:none;"
>

    <!-- =========================
         QUESTION
    ========================== -->

    <section>

        <div class="question-card">

            <div class="question-meta">

                <div
                    id="questionNumber"
                    class="question-number"
                >
                    Question 1 of 100
                </div>

                <div
                    id="difficulty"
                    class="difficulty"
                >
                    -
                </div>

            </div>


            <div
                id="questionText"
                class="question-text"
            ></div>


            <div
                id="options"
                class="options"
            ></div>


            <div
                id="saveStatus"
                class="save-status"
            ></div>


            <div class="navigation">

                <button
                    id="previousBtn"
                    class="btn-secondary"
                    onclick="previousQuestion()"
                >
                    ← Previous
                </button>


                <button
                    id="nextBtn"
                    class="btn-primary"
                    onclick="nextQuestion()"
                >
                    Next →
                </button>

            </div>

        </div>

    </section>


    <!-- =========================
         QUESTION NAVIGATOR
    ========================== -->

    <aside class="navigator">

        <div class="navigator-title">
            Question Navigator
        </div>

        <div
            id="navigatorInfo"
            class="navigator-info"
        >
            Answered: 0 / 100
        </div>


        <div
            id="questionGrid"
            class="question-grid"
        ></div>


        <div class="legend">

            <div class="legend-item">

                <span
                    class="legend-box legend-current"
                ></span>

                Current

            </div>


            <div class="legend-item">

                <span
                    class="legend-box legend-answered"
                ></span>

                Answered

            </div>


            <div class="legend-item">

                <span
                    class="legend-box legend-unanswered"
                ></span>

                Not Answered

            </div>

        </div>


        <div class="submit-area">

            <button
                class="btn-submit"
                onclick="submitExam(false)"
            >
                Submit Exam
            </button>

        </div>

    </aside>

</main>


<script>

/* ==========================================
   CONFIGURATION
========================================== */

const urlParams = new URLSearchParams(window.location.search);

const attemptId = Number(
    urlParams.get("attempt_id")
);

if (!attemptId || attemptId <= 0) {

    document.getElementById("loading").style.display = "none";

    showError("Invalid or missing exam attempt.");

    throw new Error("Invalid attempt ID");

}

/* ==========================================
   STATE
========================================== */

let questions = [];

let currentQuestionIndex = 0;

let remainingSeconds = 0;

let timerInterval = null;

let examSubmitted = false;
let violationCount = 0;
const MAX_VIOLATIONS = 3;

let autosaveInterval = null;


/*
 * Stores selected option for each question.
 *
 * Example:
 *
 * answerMap[1] = 4
 *
 * means question 1 has option 4 selected.
 */
const answerMap = {};

async function enterFullscreen() {

    try {

        await document.documentElement.requestFullscreen();

        document.getElementById(
            "loading"
        ).style.display = "none";

        document.getElementById(
            "exam"
        ).style.display = "grid";

        buildQuestionNavigator();

        startTimer();

        renderQuestion();

    } catch (error) {

        console.warn(
            "Fullscreen request failed:",
            error
        );

        alert(
            "Fullscreen is required to start the assessment."
        );
    }
}


/* ==========================================
   INITIALIZE EXAM
========================================== */

async function initializeExam() {

    try {

        /*
         * Step 1:
         * Start or resume attempt.
         */
        const startResponse = await fetch(
            `api/start_exam.php?attempt_id=${attemptId}`
        );

        const startData = await startResponse.json();


        if (!startData.success) {

            showError(startData.message);

            return;
        }


        remainingSeconds =
            Number(startData.remaining_seconds);


        /*
         * Step 2:
         * Load questions.
         */
        const questionResponse = await fetch(
            `api/get_questions.php?attempt_id=${attemptId}`
        );

        const questionData =
            await questionResponse.json();


        if (!questionData.success) {

            showError(questionData.message);

            return;
        }


        questions =
            questionData.questions;

        questions.forEach(question => {
            if (question.selected_option_id !== null) {
                answerMap[question.question_id] =
                    Number(question.selected_option_id);
            }
        });


        if (questions.length === 0) {

            showError(
                "No questions are available."
            );

            return;
        }


        /*
        * Step 3:
        * Wait for candidate to enter fullscreen.
        */
        document.getElementById(
            "loading"
        ).innerHTML =
            `
                <div>Assessment is ready.</div>

                <button
                    id="fullscreenBtn"
                    class="btn-primary"
                    style="margin-top:20px;"
                    onclick="enterFullscreen()"
                >
                    Enter Fullscreen & Start Assessment
                </button>
            `;


        /*
         * Step 4:
         * Build question navigator.
         */
        buildQuestionNavigator();


        /*
         * Step 5:
         * Start timer.
         */
        startTimer();


        /*
         * Step 6:
         * Render first question.
         */
        renderQuestion();

    }

    catch (error) {

        console.error(error);

        showError(
            "Unable to load the assessment."
        );

    }

}


/* ==========================================
   TIMER
========================================== */

function startTimer() {

    updateTimerDisplay();


    timerInterval =
        setInterval(async () => {

            remainingSeconds--;


            if (remainingSeconds <= 0) {

                remainingSeconds = 0;

                clearInterval(timerInterval);

                await submitExam(true);

                return;
            }


            updateTimerDisplay();

        }, 1000);

    startPeriodicAutosave();

}

document.addEventListener(
    "contextmenu",
    function (event) {
        event.preventDefault();
    }
);


document.addEventListener(
    "copy",
    function (event) {
        event.preventDefault();
    }
);


document.addEventListener(
    "cut",
    function (event) {
        event.preventDefault();
    }
);


document.addEventListener(
    "paste",
    function (event) {
        event.preventDefault();
    }
);

document.addEventListener(
    "keydown",
    function (event) {

        const key =
            event.key.toLowerCase();

        if (
            (event.ctrlKey &&
                ["c", "v", "x", "u", "a"].includes(key))
            ||
            event.key === "F12"
            ||
            (event.ctrlKey &&
                event.shiftKey &&
                ["i", "j"].includes(key))
        ) {

            event.preventDefault();

        }

    }
);


document.addEventListener(
    "visibilitychange",
    function () {

        if (examSubmitted) {
            return;
        }

        if (document.visibilityState === "hidden") {

            violationCount++;

            if (violationCount >= MAX_VIOLATIONS) {

                alert(
                    "Maximum assessment violations reached.\n\n" +
                    "Your assessment will now be submitted."
                );

                submitExam(true);

                return;
            }

            alert(
                `Warning: You left the assessment window.\n\n` +
                `Violation ${violationCount}/${MAX_VIOLATIONS}\n\n` +
                `Please remain on the assessment screen.`
            );

        }

    }
);

window.addEventListener(
    "blur",
    function () {

        if (examSubmitted) {
            return;
        }

        console.warn(
            "Assessment window lost focus."
        );

    }
);

function startPeriodicAutosave() {

    if (autosaveInterval) {
        clearInterval(autosaveInterval);
    }

    autosaveInterval = setInterval(
        async () => {

            if (examSubmitted) {
                return;
            }

            await syncCurrentAnswers();

        },
        30000
    );
}

async function syncCurrentAnswers() {

    const entries = Object.entries(answerMap);

    if (entries.length === 0) {
        return;
    }

    for (const [questionId, optionId] of entries) {

        if (examSubmitted) {
            return;
        }

        try {

            const response = await fetch(
                "api/save_answer.php",
                {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify({
                        attempt_id: attemptId,
                        question_id: Number(questionId),
                        selected_option_id: Number(optionId)
                    })
                }
            );

            const data = await response.json();

            if (!data.success) {
                console.warn(
                    "Autosave failed:",
                    data.message
                );
            }

        } catch (error) {

            console.warn(
                "Autosave connection error:",
                error
            );
        }
    }
}


function updateTimerDisplay() {

    const minutes =
        Math.floor(
            remainingSeconds / 60
        );


    const seconds =
        remainingSeconds % 60;


    const timer =
        document.getElementById("timer");


    timer.textContent =
        `${String(minutes).padStart(2, "0")}:${String(seconds).padStart(2, "0")}`;


    /*
     * Timer warning states.
     */
    timer.classList.remove(
        "warning",
        "danger"
    );


    if (remainingSeconds <= 300) {

        timer.classList.add("danger");

    }

    else if (remainingSeconds <= 600) {

        timer.classList.add("warning");

    }

}


/* ==========================================
   QUESTION NAVIGATOR
========================================== */

function buildQuestionNavigator() {

    const grid =
        document.getElementById(
            "questionGrid"
        );


    grid.innerHTML = "";


    questions.forEach(
        (question, index) => {

            const button =
                document.createElement("button");


            button.className =
                "question-btn";


            button.textContent =
                index + 1;


            button.type =
                "button";


            button.addEventListener(
                "click",
                () => {

                    jumpToQuestion(index);

                }
            );


            grid.appendChild(button);

        }
    );


    updateQuestionNavigator();

}


function updateQuestionNavigator() {

    const buttons =
        document.querySelectorAll(
            ".question-btn"
        );


    buttons.forEach(
        (button, index) => {

            button.classList.remove(
                "current",
                "answered"
            );


            /*
             * Answered state.
             */
            if (
                answerMap[
                    questions[index].question_id
                ]
            ) {

                button.classList.add(
                    "answered"
                );

            }


            /*
             * Current question.
             */
            if (
                index === currentQuestionIndex
            ) {

                button.classList.add(
                    "current"
                );

            }

        }
    );


    updateNavigatorInfo();

}


function updateNavigatorInfo() {

    const answeredCount =
        Object.keys(answerMap).length;


    document.getElementById(
        "navigatorInfo"
    ).textContent =
        `Answered: ${answeredCount} / ${questions.length}`;

}


function jumpToQuestion(index) {

    if (
        index < 0 ||
        index >= questions.length
    ) {
        return;
    }


    currentQuestionIndex =
        index;


    renderQuestion();

}


/* ==========================================
   RENDER QUESTION
========================================== */

function renderQuestion() {

    const question =
        questions[currentQuestionIndex];


    if (!question) {
        return;
    }


    document.getElementById(
        "questionNumber"
    ).textContent =
        `Question ${currentQuestionIndex + 1} of ${questions.length}`;


    document.getElementById(
        "questionText"
    ).textContent =
        question.question_text;


    document.getElementById(
        "difficulty"
    ).textContent =
        question.difficulty || "MCQ";


    const optionsContainer =
        document.getElementById(
            "options"
        );


    optionsContainer.innerHTML = "";


    /*
     * Existing answer for this question.
     */
    const savedOptionId =
        answerMap[
            question.question_id
        ] || null;


    question.options.forEach(
        option => {

            const label =
                document.createElement(
                    "label"
                );


            label.className =
                "option";


            if (
                savedOptionId ===
                option.option_id
            ) {

                label.classList.add(
                    "selected"
                );

            }


            label.innerHTML = `

                <input
                    type="radio"
                    name="answer"
                    value="${option.option_id}"
                    ${savedOptionId === option.option_id ? "checked" : ""}
                >

                <span class="option-text">
                    ${escapeHtml(option.option_text)}
                </span>

            `;


            const radio =
                label.querySelector(
                    "input"
                );


            radio.addEventListener(
                "change",
                () => {

                    selectAnswer(
                        question.question_id,
                        option.option_id
                    );

                }
            );


            optionsContainer.appendChild(
                label
            );

        }
    );


    /*
     * Navigation buttons.
     */
    const previousBtn =
        document.getElementById(
            "previousBtn"
        );


    const nextBtn =
        document.getElementById(
            "nextBtn"
        );


    previousBtn.disabled =
        currentQuestionIndex === 0;


    nextBtn.disabled =
        currentQuestionIndex ===
        questions.length - 1;


    updateQuestionNavigator();


    document.getElementById(
        "saveStatus"
    ).textContent = "";

}


/* ==========================================
   ANSWER SELECTION
========================================== */

async function selectAnswer(
    questionId,
    optionId
) {

    if (examSubmitted) {
        return;
    }


    /*
     * Update local state immediately.
     *
     * This makes navigation instant.
     */
    answerMap[questionId] =
        optionId;


    /*
     * Update visual selected state.
     */
    document
        .querySelectorAll(".option")
        .forEach(option => {

            option.classList.remove(
                "selected"
            );

        });


    const selectedInput =
        document.querySelector(
            `input[value="${optionId}"]`
        );


    if (selectedInput) {

        selectedInput
            .closest(".option")
            .classList.add("selected");

    }


    updateQuestionNavigator();


    /*
     * Save answer to server.
     */
    await saveAnswer(
        questionId,
        optionId
    );

}


/* ==========================================
   SAVE ANSWER API
========================================== */

async function saveAnswer(
    questionId,
    optionId
) {

    const saveStatus =
        document.getElementById(
            "saveStatus"
        );


    saveStatus.className =
        "save-status";


    saveStatus.textContent =
        "Saving...";


    try {

        const response =
            await fetch(
                "api/save_answer.php",
                {
                    method: "POST",

                    headers: {
                        "Content-Type":
                            "application/json"
                    },

                    body: JSON.stringify({

                        attempt_id:
                            attemptId,

                        question_id:
                            questionId,

                        selected_option_id:
                            optionId

                    })
                }
            );


        const data =
            await response.json();


        if (!data.success) {

            saveStatus.className =
                "save-status error";


            saveStatus.textContent =
                data.message ||
                "Unable to save answer.";


            return;
        }


        saveStatus.className =
            "save-status success";


        saveStatus.textContent =
            "Answer saved";

    }

    catch (error) {

        console.error(error);


        saveStatus.className =
            "save-status error";


        saveStatus.textContent =
            "Connection error. Answer may not have been saved.";

    }

}


/* ==========================================
   NEXT QUESTION
========================================== */

function nextQuestion() {

    if (
        currentQuestionIndex <
        questions.length - 1
    ) {

        currentQuestionIndex++;

        renderQuestion();

    }

}


/* ==========================================
   PREVIOUS QUESTION
========================================== */

function previousQuestion() {

    if (
        currentQuestionIndex > 0
    ) {

        currentQuestionIndex--;

        renderQuestion();

    }

}


/* ==========================================
   SUBMIT EXAM
========================================== */

async function submitExam(
    autoSubmit = false
) {

    if (examSubmitted) {
        return;
    }


    /*
     * Manual submission confirmation.
     */
    if (!autoSubmit) {

        const unanswered =
            questions.length -
            Object.keys(answerMap).length;


        let message =
            "Are you sure you want to submit the exam?";


        if (unanswered > 0) {

            message +=
                `\n\nYou have ${unanswered} unanswered question(s).`;

        }


        const confirmed =
            confirm(message);


        if (!confirmed) {

            return;

        }

    }


    examSubmitted = true;


    clearInterval(
        timerInterval
    );
    if (autosaveInterval) {
        clearInterval(autosaveInterval);
        autosaveInterval = null;
    }


    try {

        const response =
            await fetch(
                "api/submit_exam.php",
                {
                    method: "POST",

                    headers: {
                        "Content-Type":
                            "application/json"
                    },

                    body: JSON.stringify({
                        attempt_id:
                            attemptId
                    })
                }
            );


        const data =
            await response.json();


        if (data.success) {

            alert(
                autoSubmit
                    ? "Time is over. Your exam has been submitted."
                    : "Exam submitted successfully."
            );


            /*
             * Disable exam controls.
             */
            document
                .querySelectorAll(
                    "button, input"
                )
                .forEach(element => {

                    element.disabled = true;

                });


            /*
             * Show completion message.
             */
            document.getElementById(
                "questionText"
            ).textContent =
                "Assessment submitted successfully.";


            document.getElementById(
                "options"
            ).innerHTML = "";


            document.getElementById(
                "saveStatus"
            ).textContent =
                "Your responses have been submitted for evaluation.";

        }

        else {

            /*
             * Allow another submission attempt
             * if server rejected the request.
             */
            examSubmitted = false;


            alert(
                data.message ||
                "Unable to submit the exam."
            );

        }

    }

    catch (error) {

        console.error(error);


        examSubmitted = false;


        alert(
            "Unable to submit the exam. Please check your connection."
        );

    }

}


/* ==========================================
   HTML ESCAPING
========================================== */

function escapeHtml(value) {

    const div =
        document.createElement("div");


    div.textContent =
        value;


    return div.innerHTML;

}


/* ==========================================
   ERROR
========================================== */

function showError(message) {

    document.getElementById(
        "loading"
    ).style.display = "none";


    const error =
        document.getElementById(
            "error"
        );


    error.style.display =
        "block";


    error.textContent =
        message;

}


/* ==========================================
   START
========================================== */

initializeExam();

</script>

</body>

</html>