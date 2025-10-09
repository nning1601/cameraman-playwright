const prompt = require('prompt-sync')();

function geeting(name) {
    console.log('Hello' + name );
}
function UserInput(callback) {
    const name = prompt('Enter your name: ');
    callback(name);
}
UserInput(geeting);
